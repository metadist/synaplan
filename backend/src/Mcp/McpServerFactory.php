<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Chat;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Message\MessageProcessor;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\StorageQuotaService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Schema\ToolAnnotations;
use Mcp\Server;
use Mcp\Server\Builder;
use Mcp\Server\Session\Psr16SessionStore;
use Mcp\Server\Session\SessionStoreInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Builds a per-request MCP {@see Server} exposing a curated, user-scoped set of
 * Synaplan capabilities as MCP tools.
 *
 * Design notes:
 *  - The server is rebuilt for every HTTP request so the registered tool
 *    closures can capture the authenticated {@see User}; authorization is always
 *    enforced per request by the Symfony `mcp` firewall, never by the MCP
 *    session. The MCP session only carries protocol plumbing (request counters,
 *    outgoing queues) and is persisted in the shared cache so the
 *    initialize → tools/list → tools/call round-trip survives across the
 *    stateless HTTP requests.
 *  - Tools are a deliberately small, read-only allowlist for the first
 *    iteration (see _devextras/planning/.../02-mcp-integration/00-ROADMAP-2026.md).
 */
final class McpServerFactory
{
    private const SERVER_NAME = 'Synaplan';
    private const SERVER_VERSION = '0.1.0';
    private const SESSION_TTL_SECONDS = 3600;

    private ?SessionStoreInterface $sessionStore = null;

    public function __construct(
        private readonly VectorSearchService $vectorSearch,
        private readonly UserMemoryService $memoryService,
        private readonly MessageProcessor $messageProcessor,
        private readonly VectorizationService $vectorization,
        private readonly FileStorageService $fileStorage,
        private readonly StorageQuotaService $storageQuota,
        private readonly PromptRepository $promptRepository,
        private readonly ChatRepository $chatRepository,
        private readonly MessageRepository $messageRepository,
        private readonly RateLimitService $rateLimit,
        private readonly EntityManagerInterface $em,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Build a server instance with all tools bound to the given user.
     */
    public function build(User $user): Server
    {
        $builder = Server::builder()
            ->setServerInfo(
                self::SERVER_NAME,
                self::SERVER_VERSION,
                'Synaplan MCP server — knowledge retrieval (RAG) and user memories.',
            )
            ->setInstructions(
                'Use rag_search to retrieve relevant chunks from the user\'s vectorized '
                .'documents and chat knowledge base, and memory_search to look up facts the '
                .'user has stored about themselves. Both tools are scoped to the authenticated '
                .'Synaplan account.',
            )
            ->setLogger($this->logger)
            ->setSession($this->sessionStore())
            ->addTool(
                $this->chatHandler($user),
                'synaplan_chat',
                'Synaplan chat',
                'Send a message through Synaplan\'s full AI pipeline — intent classification, '
                .'web search, RAG over the user\'s documents, long-term memories, and inference — '
                .'and return the synthesized answer. This is richer than a raw model call: it uses '
                .'the authenticated account\'s knowledge base and memories.',
                // Not read-only (creates a message record), additive only, may reach the
                // open web via the pipeline's web-search step.
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, openWorldHint: true),
                self::chatSchema(),
            )
            ->addTool(
                $this->ragSearchHandler($user),
                'rag_search',
                'RAG knowledge search',
                'Semantic search across the authenticated user\'s vectorized documents and '
                .'knowledge base. Returns the most relevant text chunks with similarity scores.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::ragSearchSchema(),
            )
            ->addTool(
                $this->memorySearchHandler($user),
                'memory_search',
                'User memory search',
                'Search the authenticated user\'s stored long-term memories (preferences, '
                .'facts, context) using semantic similarity.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::memorySearchSchema(),
            )
            ->addTool(
                $this->memoryAddHandler($user),
                'memory_add',
                'Add a user memory',
                'Store a new long-term memory for the authenticated user — a durable fact or '
                .'preference that Synaplan will recall in future conversations.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                self::memoryAddSchema(),
            )
            ->addTool(
                $this->fileIngestHandler($user),
                'file_ingest',
                'Ingest a document for RAG',
                'Add a text document to the authenticated user\'s knowledge base. The content is '
                .'chunked, embedded, and stored so it becomes retrievable via rag_search.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                self::fileIngestSchema(),
            )
            ->addTool(
                $this->ragSimilarHandler($user),
                'rag_similar',
                'Find similar chunks',
                'Find document chunks similar to a given source message/document id (the '
                .'`message_id` returned by rag_search).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::ragSimilarSchema(),
            )
            ->addTool(
                $this->listChatsHandler($user),
                'list_chats',
                'List chats',
                'List the authenticated user\'s conversations (most recently updated first).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::listChatsSchema(),
            )
            ->addTool(
                $this->getMessagesHandler($user),
                'get_messages',
                'Get chat messages',
                'Return the messages of one of the user\'s chats, in chronological order.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::getMessagesSchema(),
            )
            ->addTool(
                $this->listPromptsHandler($user),
                'list_prompts',
                'List task prompts',
                'List the user\'s available Synaplan task prompts (internal `tools:*` prompts excluded).',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::listPromptsSchema(),
            )
            ->addResourceTemplate(
                $this->fileResourceHandler($user),
                'synaplan://file/{id}',
                'synaplan_file',
                'Synaplan document',
                'The extracted text of one of the authenticated user\'s documents, addressed by file id.',
                'text/plain',
            )
            ->addResourceTemplate(
                $this->memoryResourceHandler($user),
                'synaplan://memory/{id}',
                'synaplan_memory',
                'Synaplan memory',
                'One of the authenticated user\'s stored long-term memories, addressed by memory id (JSON).',
                'application/json',
            );

        $this->registerPrompts($builder, $user);

        return $builder->build();
    }

    /**
     * Expose the user's task prompts as MCP prompts (host-selectable templates).
     *
     * Internal plumbing prompts (`tools:*`) are excluded — both by the repository
     * query and defensively here. Each prompt takes an optional `input` argument
     * that is appended to the instruction text.
     */
    private function registerPrompts(Builder $builder, User $user): void
    {
        $seen = [];

        foreach ($this->promptRepository->findAllForUser((int) $user->getId()) as $prompt) {
            $topic = $prompt->getTopic();

            if (str_starts_with($topic, 'tools:') || isset($seen[$topic])) {
                continue;
            }
            $seen[$topic] = true;

            $description = '' !== trim($prompt->getShortDescription())
                ? $prompt->getShortDescription()
                : \sprintf('Synaplan task prompt: %s', $topic);

            $builder->addPrompt(
                $this->promptHandler($prompt->getPrompt()),
                $topic,
                null,
                $description,
            );
        }
    }

    /**
     * @return \Closure(string): list<array{role: string, content: string}>
     */
    private function promptHandler(string $instruction): \Closure
    {
        return static function (string $input = '') use ($instruction): array {
            $content = '' !== trim($input)
                ? $instruction."\n\n".$input
                : $instruction;

            return [['role' => 'user', 'content' => $content]];
        };
    }

    /**
     * @return \Closure(int, int): array<string, mixed>
     */
    private function ragSimilarHandler(User $user): \Closure
    {
        return function (int $message_id, int $limit = 10) use ($user): array {
            $results = $this->vectorSearch->findSimilar(
                $message_id,
                (int) $user->getId(),
                max(1, min(50, $limit)),
            );

            return [
                'source_message_id' => $message_id,
                'total_results' => \count($results),
                'results' => array_map(static fn (array $r): array => [
                    'chunk_id' => $r['chunk_id'] ?? null,
                    'message_id' => $r['message_id'] ?? null,
                    'text' => $r['chunk_text'] ?? '',
                    'score' => $r['distance'] ?? null,
                ], $results),
            ];
        };
    }

    /**
     * @return \Closure(int): array<string, mixed>
     */
    private function listChatsHandler(User $user): \Closure
    {
        return function (int $limit = 50) use ($user): array {
            $chats = $this->chatRepository->findByUser((int) $user->getId());
            $chats = \array_slice($chats, 0, max(1, min(200, $limit)));

            return [
                'total' => \count($chats),
                'chats' => array_map(static fn (Chat $c): array => [
                    'id' => $c->getId(),
                    'title' => $c->getTitle() ?? 'New Chat',
                    'source' => $c->getSource(),
                    'created_at' => $c->getCreatedAt()->format('c'),
                    'updated_at' => $c->getUpdatedAt()->format('c'),
                    'message_count' => $c->getMessages()->count(),
                ], $chats),
            ];
        };
    }

    /**
     * @return \Closure(int, int): array<string, mixed>
     */
    private function getMessagesHandler(User $user): \Closure
    {
        return function (int $chat_id, int $limit = 50) use ($user): array {
            $chat = $this->chatRepository->find($chat_id);
            if (!$chat instanceof Chat || $chat->getUserId() !== (int) $user->getId()) {
                return ['success' => false, 'error' => 'Chat not found.'];
            }

            // Newest N, then chronological order for reading.
            $messages = $this->messageRepository->findBy(
                ['chatId' => $chat->getId()],
                ['unixTimestamp' => 'DESC', 'id' => 'DESC'],
                max(1, min(100, $limit)),
            );
            $messages = array_reverse($messages);

            return [
                'success' => true,
                'chat_id' => $chat->getId(),
                'title' => $chat->getTitle() ?? 'New Chat',
                'messages' => array_map(static fn (Message $m): array => [
                    'id' => $m->getId(),
                    'role' => 'IN' === $m->getDirection() ? 'user' : 'assistant',
                    'text' => $m->getText(),
                    'topic' => $m->getTopic(),
                    'timestamp' => $m->getUnixTimestamp(),
                ], $messages),
            ];
        };
    }

    /**
     * @return \Closure(): array<string, mixed>
     */
    private function listPromptsHandler(User $user): \Closure
    {
        return function () use ($user): array {
            $prompts = [];
            foreach ($this->promptRepository->findAllForUser((int) $user->getId()) as $prompt) {
                $topic = $prompt->getTopic();
                if (str_starts_with($topic, 'tools:')) {
                    continue;
                }
                $prompts[] = [
                    'topic' => $topic,
                    'description' => $prompt->getShortDescription(),
                ];
            }

            return ['total' => \count($prompts), 'prompts' => $prompts];
        };
    }

    /**
     * Resource template handler for `synaplan://file/{id}` — returns the document's text.
     *
     * @return \Closure(string, string): string
     */
    private function fileResourceHandler(User $user): \Closure
    {
        return function (string $id, string $uri) use ($user): string {
            $file = $this->em->getRepository(File::class)->findOneBy([
                'id' => (int) $id,
                'userId' => (int) $user->getId(),
            ]);

            if (!$file instanceof File) {
                throw new ResourceNotFoundException($uri);
            }

            return $file->getFileText();
        };
    }

    /**
     * Resource template handler for `synaplan://memory/{id}` — returns the memory as JSON.
     *
     * @return \Closure(string, string): array<string, mixed>
     */
    private function memoryResourceHandler(User $user): \Closure
    {
        return function (string $id, string $uri) use ($user): array {
            if (!$this->memoryService->isAvailable()) {
                throw new ResourceNotFoundException($uri);
            }

            $memory = $this->memoryService->getMemoryById((int) $id, $user);
            if (null === $memory) {
                throw new ResourceNotFoundException($uri);
            }

            return $memory->toArray();
        };
    }

    /**
     * @return \Closure(string): array<string, mixed>
     */
    private function chatHandler(User $user): \Closure
    {
        return function (string $message) use ($user): array {
            $rateLimit = $this->rateLimit->checkLimit($user, 'MESSAGES');
            if (!($rateLimit['allowed'] ?? false)) {
                return [
                    'success' => false,
                    'error' => 'Rate limit exceeded.',
                    'limit' => $rateLimit['limit'] ?? null,
                    'used' => $rateLimit['used'] ?? null,
                    'reset_at' => $rateLimit['reset_at'] ?? null,
                ];
            }

            // Mirrors WebhookController::generic(): persist an IN message, run the
            // full pipeline synchronously, return the assistant's answer.
            $entity = new Message();
            $entity->setUserId((int) $user->getId());
            $entity->setTrackingId(time());
            $entity->setProviderIndex('MCP');
            $entity->setUnixTimestamp(time());
            $entity->setDateTime(date('YmdHis'));
            $entity->setMessageType('API');
            $entity->setFile(0);
            $entity->setTopic('CHAT');
            $entity->setLanguage('en');
            $entity->setText($message);
            $entity->setDirection('IN');
            $entity->setStatus('processing');

            $this->em->persist($entity);
            $this->em->flush();

            $result = $this->messageProcessor->process($entity);

            if (!($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => $result['error'] ?? 'Processing failed.',
                ];
            }

            $response = $result['response'] ?? [];
            $answer = $response['content'] ?? '';
            $meta = $response['metadata'] ?? [];
            $classification = $result['classification'] ?? [];

            $this->rateLimit->recordUsage($user, 'MESSAGES', [
                'provider' => $meta['provider'] ?? 'unknown',
                'model' => $meta['model'] ?? 'unknown',
                'usage' => $meta['usage'] ?? [],
                'model_id' => $meta['model_id'] ?? null,
                'source' => 'MCP',
                'response_text' => $answer,
                'input_text' => $message,
            ]);

            return [
                'success' => true,
                'answer' => $answer,
                'topic' => $classification['topic'] ?? null,
                'intent' => $classification['intent'] ?? null,
                'files' => \is_array($meta['files'] ?? null)
                    ? $meta['files']
                    : (isset($meta['file']) ? [$meta['file']] : []),
            ];
        };
    }

    /**
     * @return \Closure(string, int, float, ?string): array<string, mixed>
     */
    private function ragSearchHandler(User $user): \Closure
    {
        return function (string $query, int $limit = 10, float $min_score = 0.3, ?string $group_key = null) use ($user): array {
            $results = $this->vectorSearch->semanticSearch(
                $query,
                (int) $user->getId(),
                $group_key,
                max(1, min(50, $limit)),
                max(0.0, min(1.0, $min_score)),
            );

            return [
                'query' => $query,
                'total_results' => \count($results),
                'provider' => $this->vectorSearch->getProviderName(),
                'results' => array_map(static fn (array $r): array => [
                    'chunk_id' => $r['chunk_id'] ?? null,
                    'message_id' => $r['message_id'] ?? null,
                    'text' => $r['chunk_text'] ?? '',
                    'score' => $r['score'] ?? null,
                ], $results),
            ];
        };
    }

    /**
     * @return \Closure(string, string, ?string): array<string, mixed>
     */
    private function fileIngestHandler(User $user): \Closure
    {
        return function (string $name, string $content, ?string $group_key = null) use ($user): array {
            if ('' === trim($content)) {
                return ['success' => false, 'error' => 'content must not be empty.'];
            }

            $userId = (int) $user->getId();
            $size = \strlen($content);

            try {
                $this->storageQuota->checkStorageLimit($user, $size);
            } catch (\RuntimeException $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            }

            $title = '' !== trim($name) ? trim($name) : 'mcp-document';

            $stored = $this->fileStorage->storeRawContent($content, $userId, $title.'.txt', 'text/plain');
            if (!$stored['success']) {
                return ['success' => false, 'error' => $stored['error'] ?? 'Failed to store content.'];
            }

            $file = (new File())
                ->setUserId($userId)
                ->setFilePath((string) $stored['path'])
                ->setFileType('txt')
                ->setFileName($title)
                ->setFileSize((int) $stored['size'])
                ->setFileMime('text/plain')
                ->setFileText($content)
                ->setGroupKey($group_key)
                ->setStatus('extracted');

            $this->em->persist($file);
            $this->em->flush();

            $vector = $this->vectorization->vectorizeAndStore(
                $content,
                $userId,
                (int) $file->getId(),
                $group_key ?? '',
                FileHelper::getFileTypeCode('txt'),
            );

            if (!($vector['success'] ?? false)) {
                // Keep the stored document (text is searchable later via re-vectorize)
                // but surface the failure to the caller.
                return [
                    'success' => false,
                    'file_id' => $file->getId(),
                    'error' => $vector['error'] ?? 'Vectorization failed.',
                ];
            }

            $file->setStatus('vectorized');
            $this->em->flush();

            return [
                'success' => true,
                'file_id' => $file->getId(),
                'name' => $title,
                'group_key' => $group_key,
                'chunks_created' => $vector['chunks_created'] ?? 0,
                'provider' => $vector['provider'] ?? null,
            ];
        };
    }

    /**
     * @return \Closure(string, string, string): array<string, mixed>
     */
    private function memoryAddHandler(User $user): \Closure
    {
        return function (string $category, string $key, string $value) use ($user): array {
            if (!$this->memoryService->isAvailable()) {
                return [
                    'success' => false,
                    'error' => 'Memory service is currently unavailable.',
                ];
            }

            try {
                $memory = $this->memoryService->createMemory($user, $category, $key, $value, 'user_created');
            } catch (\InvalidArgumentException $e) {
                return ['success' => false, 'error' => $e->getMessage()];
            } catch (MemoryServiceUnavailableException) {
                return ['success' => false, 'error' => 'Memory service is currently unavailable.'];
            }

            return [
                'success' => true,
                'memory' => $memory->toArray(),
            ];
        };
    }

    /**
     * @return \Closure(string, ?string, int): array<string, mixed>
     */
    private function memorySearchHandler(User $user): \Closure
    {
        return function (string $query, ?string $category = null, int $limit = 5) use ($user): array {
            if (!$this->memoryService->isAvailable()) {
                return [
                    'available' => false,
                    'query' => $query,
                    'total_results' => 0,
                    'memories' => [],
                    'error' => 'Memory service is currently unavailable.',
                ];
            }

            $memories = $this->memoryService->searchMemories(
                $user,
                $query,
                $category,
                max(1, min(50, $limit)),
            );

            return [
                'available' => true,
                'query' => $query,
                'total_results' => \count($memories),
                'memories' => $memories,
            ];
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function ragSimilarSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message_id' => [
                    'type' => 'integer',
                    'description' => 'The source document/message id to find similar chunks for '
                        .'(use a `message_id` returned by rag_search).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                    'description' => 'Maximum number of similar chunks to return (1-50).',
                ],
            ],
            'required' => ['message_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listChatsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                    'description' => 'Maximum number of chats to return (1-200).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function getMessagesSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'chat_id' => [
                    'type' => 'integer',
                    'description' => 'The id of the chat (from list_chats) to read messages from.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 100,
                    'default' => 50,
                    'description' => 'Maximum number of (most recent) messages to return (1-100).',
                ],
            ],
            'required' => ['chat_id'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function listPromptsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => new \stdClass(),
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function chatSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'message' => [
                    'type' => 'string',
                    'description' => 'The message or question to send through Synaplan\'s full AI '
                        .'pipeline (classification, web search, RAG over the user\'s documents, '
                        .'memories, and inference).',
                ],
            ],
            'required' => ['message'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function ragSearchSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Natural-language search query.',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 10,
                    'description' => 'Maximum number of chunks to return (1-50).',
                ],
                'min_score' => [
                    'type' => 'number',
                    'minimum' => 0,
                    'maximum' => 1,
                    'default' => 0.3,
                    'description' => 'Minimum similarity score (0-1) a chunk must reach to be returned.',
                ],
                'group_key' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional document group key to scope the search to a single document/collection.',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fileIngestSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'A human-readable name/title for the document.',
                ],
                'content' => [
                    'type' => 'string',
                    'description' => 'The text content to ingest. It is chunked, embedded, and made '
                        .'retrievable via rag_search.',
                ],
                'group_key' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional group key to scope the document. Pass the same value to '
                        .'rag_search to retrieve only this group; omit it to search the whole corpus.',
                ],
            ],
            'required' => ['name', 'content'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function memoryAddSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'description' => 'Category for the memory, e.g. preferences, work, personal, health.',
                ],
                'key' => [
                    'type' => 'string',
                    'minLength' => 3,
                    'description' => 'Short identifier for the memory (min 3 characters), e.g. tech_stack.',
                ],
                'value' => [
                    'type' => 'string',
                    'minLength' => 1,
                    'description' => 'The fact or preference to remember.',
                ],
            ],
            'required' => ['category', 'key', 'value'],
            'additionalProperties' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function memorySearchSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => [
                    'type' => 'string',
                    'description' => 'Natural-language query describing the memory to look up.',
                ],
                'category' => [
                    'type' => ['string', 'null'],
                    'description' => 'Optional category filter (e.g. preferences, work, personal).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 50,
                    'default' => 5,
                    'description' => 'Maximum number of memories to return (1-50).',
                ],
            ],
            'required' => ['query'],
            'additionalProperties' => false,
        ];
    }

    private function sessionStore(): SessionStoreInterface
    {
        return $this->sessionStore ??= new Psr16SessionStore(
            new Psr16Cache($this->cache),
            'mcp-sess-',
            self::SESSION_TTL_SECONDS,
        );
    }
}
