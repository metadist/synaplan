<?php

declare(strict_types=1);

namespace App\Mcp;

use App\Entity\Chat;
use App\Entity\DesktopDevice;
use App\Entity\DesktopJob;
use App\Entity\File;
use App\Entity\Message;
use App\Entity\User;
use App\Observability\EventRingStore;
use App\Repository\ChatRepository;
use App\Repository\DesktopDeviceRepository;
use App\Repository\MessageRepository;
use App\Repository\PromptRepository;
use App\Service\Agent\AssistantAliasResolver;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobContract;
use App\Service\Desktop\DesktopJobResultNotifier;
use App\Service\Desktop\DesktopJobStore;
use App\Service\Desktop\Exception\ResultTooLargeException;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\File\VectorizationService;
use App\Service\Memory\UserMemoryService;
use App\Service\Message\GeneratedMediaTextRenderer;
use App\Service\Message\MessageProcessor;
use App\Service\RAG\VectorSearchService;
use App\Service\RateLimitService;
use App\Service\Usage\FacadeMeteredChat;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Exception\ResourceNotFoundException;
use Mcp\Exception\ToolCallException;
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
 *    closures can capture the authenticated {@see User}. `initialize` and
 *    `tools/list` are public ({@see McpAnonymousAccess}); every other method
 *    requires the Symfony `mcp` firewall user. The MCP session only carries
 *    protocol plumbing (request counters,
 *    outgoing queues) and is persisted in the shared cache so the
 *    initialize → tools/list → tools/call round-trip survives across the
 *    stateless HTTP requests.
 *  - Tools are a curated allowlist (read + write) covering chat, RAG, memories,
 *    document ingestion, and conversation browsing
 *    (see _devextras/planning/.../02-mcp-integration/00-ROADMAP-2026.md). Mutating
 *    tools enforce per-call rate limits and record usage; tool-level failures are
 *    reported to the client via the MCP `isError` flag (thrown ToolCallException).
 */
final class McpServerFactory
{
    private const SERVER_NAME = 'Synaplan';
    private const SERVER_VERSION = '0.1.0';
    private const SESSION_TTL_SECONDS = 3600;

    private const INSTRUCTIONS = 'Synaplan exposes the authenticated account\'s knowledge and assistant as tools. '
        .'Use synaplan_chat for a full answer through Synaplan\'s pipeline; rag_search / '
        .'rag_similar to retrieve document chunks; memory_search / memory_add to read and '
        .'store long-term memories; file_ingest to add documents to the knowledge base; and '
        .'list_chats / get_messages / list_prompts / list_assistants to browse conversations, task prompts and assistants. '
        .'Documents and memories are also available as resources, and task prompts as MCP '
        .'prompts. Everything is scoped to the authenticated Synaplan account.';

    private const DISCOVERY_INSTRUCTIONS = 'This session is not signed in: it lists the tools only. '
        .'Connect with a Synaplan API key or your Synaplan account to call them.';

    /** Cap resource bodies so a single large document can't produce a huge MCP response. */
    private const MAX_RESOURCE_CHARS = 100000;

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
        private readonly ConversationSummaryRefreshDispatcher $summaryRefreshDispatcher,
        private readonly DesktopAgentConfig $desktopAgentConfig,
        private readonly DesktopJobStore $desktopJobStore,
        private readonly DesktopDeviceRepository $desktopDeviceRepository,
        private readonly DesktopJobResultNotifier $desktopJobResultNotifier,
        private readonly LoggerInterface $logger,
        private readonly EventRingStore $eventRing,
        private readonly ?AssistantAliasResolver $assistantAliases = null,
        private readonly ?GeneratedMediaTextRenderer $mediaTextRenderer = null,
    ) {
    }

    /**
     * Build a server instance with all tools bound to the given user.
     *
     * When the request is authenticated by a scoped key bound to a paired
     * computer ($device is non-null) AND the Desktop feature is enabled, two
     * extra tools are exposed — `agent_checkin` (lease work) and
     * `agent_report_result` (return a result). They are the ONLY way a device
     * pulls jobs; there is no push and no server-supplied shell command.
     * A normal MCP client (Claude/Cursor, or a non-desktop key) never sees them.
     */
    public function build(User $user, ?DesktopDevice $device = null): Server
    {
        $builder = $this->newBuilder();
        $this->registerPublicTools($builder, $this->authenticatedHandlers($user, $device));

        $builder
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

        // Admin-only troubleshooting tool. The `mcp` firewall authenticates any
        // valid API key (ROLE_USER), so the admin gate lives here: the tool is
        // invisible to non-admins in tools/list and refuses in the handler.
        if (self::hasAdminRole($user)) {
            $builder->addTool(
                $this->recentErrorsHandler($user),
                'recent_errors',
                'Recent production errors',
                'Query the redacted operational event ring for recent production errors and '
                .'notable events (admin only). mode=summary returns counts by level/event/route '
                .'for a time window; mode=recent returns the newest events with optional '
                .'level/query/request_id filters. Every field is allow-listed and free text is '
                .'scrubbed — no chat content, user emails, document text or secrets are returned.',
                new ToolAnnotations(readOnlyHint: true, openWorldHint: false),
                self::recentErrorsSchema(),
            );
        }

        if ($device instanceof DesktopDevice && $this->desktopAgentConfig->isEnabled((int) $user->getId())) {
            $this->registerDesktopAgentTools($builder, $device);
        }

        $this->registerPrompts($builder, $user);

        return $builder->build();
    }

    /**
     * Public catalog for unauthenticated discovery. Handlers refuse; the
     * controller rejects tools/call before this server runs.
     */
    public function buildDiscovery(): Server
    {
        $builder = $this->newBuilder(self::INSTRUCTIONS.' '.self::DISCOVERY_INSTRUCTIONS);
        (new McpPublicTools())->registerDiscovery($builder, null !== $this->assistantAliases);

        return $builder->build();
    }

    private function newBuilder(string $instructions = self::INSTRUCTIONS): Builder
    {
        return Server::builder()
            ->setServerInfo(
                self::SERVER_NAME,
                self::SERVER_VERSION,
                'Synaplan MCP server — knowledge retrieval (RAG) and user memories.',
            )
            ->setInstructions($instructions)
            ->setLogger($this->logger)
            ->setSession($this->sessionStore());
    }

    /**
     * @param array<string, \Closure> $handlers
     */
    private function registerPublicTools(Builder $builder, array $handlers): void
    {
        (new McpPublicTools())->register($builder, $handlers, null !== $this->assistantAliases);
    }

    /**
     * @return array<string, \Closure>
     */
    private function authenticatedHandlers(User $user, ?DesktopDevice $device): array
    {
        $handlers = [
            'synaplan_chat' => $this->chatHandler($user, $device),
            'rag_search' => $this->ragSearchHandler($user),
            'memory_search' => $this->memorySearchHandler($user),
            'memory_add' => $this->memoryAddHandler($user),
            'file_ingest' => $this->fileIngestHandler($user),
            'rag_similar' => $this->ragSimilarHandler($user),
            'list_chats' => $this->listChatsHandler($user),
            'get_messages' => $this->getMessagesHandler($user),
            'list_prompts' => $this->listPromptsHandler($user),
        ];
        if (null !== $this->assistantAliases) {
            $handlers['list_assistants'] = $this->listAssistantsHandler($user, $device);
        }

        return $handlers;
    }

    /**
     * Register the Synaplan Desktop check-in tools for a paired device.
     *
     * These are gated twice: the device must be present (scoped key → device
     * row) and the global flag must be ON. A revoked device's key is already
     * inactive, so it never authenticates this far.
     */
    private function registerDesktopAgentTools(Builder $builder, DesktopDevice $device): void
    {
        $builder
            ->addTool(
                $this->agentCheckinHandler($device),
                'agent_checkin',
                'Desktop agent check-in',
                'Synaplan Desktop calls this to lease the next queued job for THIS computer. '
                .'Returns at most one job as {jobId, type, input:{skill,prompt,fileIds}, leaseToken, '
                .'leaseExpires}, plus next_call_at (when to poll again). The input never contains a '
                .'shell command — only a named skill the device already has installed.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                self::agentCheckinSchema(),
            )
            ->addTool(
                $this->agentReportResultHandler($device),
                'agent_report_result',
                'Desktop agent report result',
                'Synaplan Desktop calls this to report the outcome of a leased job, identified by its '
                .'leaseToken. status is "succeeded" or "failed"; a refusal (unknown/disabled skill) is '
                .'a normal "failed" with an errorCode, not an error.',
                new ToolAnnotations(readOnlyHint: false, destructiveHint: false, idempotentHint: false, openWorldHint: false),
                self::agentReportResultSchema(),
            );
    }

    /**
     * @return \Closure(int, list<mixed>, ?list<mixed>, string, string): array<string, mixed>
     */
    private function agentCheckinHandler(DesktopDevice $device): \Closure
    {
        return function (int $protocol = DesktopJobContract::PROTOCOL_VERSION, array $capabilities = [], ?array $enabledSkills = null, string $agentKind = 'synaplan-desktop', string $status = 'idle') use ($device): array {
            $now = time();

            $device->touchLastSeen();
            if ([] !== $capabilities) {
                $device->setCapabilities(array_values(array_filter(
                    $capabilities,
                    static fn ($c): bool => \is_string($c),
                )));
            }
            // null means the field was omitted: older clients keep the stored list.
            // An array, including an empty one, replaces it.
            if (null !== $enabledSkills) {
                $device->setEnabledSkills(self::normalizeSkillNames($enabledSkills));
            }
            $this->desktopDeviceRepository->save($device);

            // Never guess for a client speaking a protocol we don't know — hand
            // back no work and a far next check-in (contract invariant C9).
            if (DesktopJobContract::PROTOCOL_VERSION !== $protocol) {
                return [
                    'protocol' => DesktopJobContract::PROTOCOL_VERSION,
                    'jobs' => [],
                    'next_call_at' => $now + DesktopJobContract::NEXT_CALL_UNKNOWN_PROTOCOL_SECONDS,
                ];
            }

            if (!$device->isActive()) {
                return [
                    'protocol' => DesktopJobContract::PROTOCOL_VERSION,
                    'jobs' => [],
                    'next_call_at' => $now + DesktopJobContract::NEXT_CALL_IDLE_SECONDS,
                ];
            }

            $job = $this->desktopJobStore->leaseForDevice($device);
            $jobs = $job instanceof DesktopJob ? [DesktopJobContract::buildDevicePayload($job)] : [];

            return [
                'protocol' => DesktopJobContract::PROTOCOL_VERSION,
                'jobs' => $jobs,
                'next_call_at' => $now + ([] === $jobs
                    ? DesktopJobContract::NEXT_CALL_IDLE_SECONDS
                    : DesktopJobContract::NEXT_CALL_ACTIVE_SECONDS),
            ];
        };
    }

    /**
     * @return \Closure(string, string, ?array<string, mixed>, ?string): array<string, mixed>
     */
    private function agentReportResultHandler(DesktopDevice $device): \Closure
    {
        return function (string $leaseToken, string $status, ?array $result = null, ?string $errorCode = null) use ($device): array {
            if ('' === trim($leaseToken)) {
                throw new ToolCallException('leaseToken is required.');
            }

            if (null !== $errorCode && !DesktopJobContract::isValidErrorCode($errorCode)) {
                throw new ToolCallException('Unknown errorCode.');
            }

            try {
                $job = $this->desktopJobStore->reportResult($device, $leaseToken, $status, $result, $errorCode);
            } catch (ResultTooLargeException $e) {
                throw new ToolCallException($e->getMessage());
            }

            // Unknown / stale / foreign lease token → a clean tool error, not a 500.
            if (!$job instanceof DesktopJob) {
                throw new ToolCallException('Unknown or stale lease token, or invalid status.');
            }

            // Post the "done" note into the originating chat (if any). The
            // result text is untrusted device content, stamped as such.
            $this->desktopJobResultNotifier->notify($job);

            return [
                'success' => true,
                'jobId' => $job->getId(),
                'status' => $job->getStatus(),
            ];
        };
    }

    /**
     * Skill names the computer may be offered. Invalid entries are dropped.
     * The caller decides whether an omitted field keeps the stored list.
     *
     * @param list<mixed> $enabledSkills
     *
     * @return list<string>
     */
    private static function normalizeSkillNames(array $enabledSkills): array
    {
        $skills = [];
        foreach ($enabledSkills as $skill) {
            if (!\is_string($skill)) {
                continue;
            }
            $skill = trim($skill);
            if (1 !== preg_match('/^[a-z0-9-]{1,64}$/', $skill)) {
                continue;
            }
            $skills[$skill] = $skill;
        }

        return array_values($skills);
    }

    /**
     * The frozen `agent_checkin` argument schema (protocol 1, DS18). Public so
     * the contract-fixture test can assert the committed fixtures against the
     * live schema (invariant C9).
     *
     * @return array<string, mixed>
     */
    public static function agentCheckinSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'protocol' => [
                    'type' => 'integer',
                    'default' => DesktopJobContract::PROTOCOL_VERSION,
                    'description' => 'The desktop job protocol version the client speaks. Currently 1.',
                ],
                'capabilities' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Job kinds this computer supports (v1: ["skill.run"]).',
                ],
                'enabledSkills' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'Names of skills that may run while the user is away. Omit the field to keep the last report. Send an empty list when none may run. A hint so the server can skip jobs the device would refuse — an optimization, not a security boundary.',
                ],
                'agentKind' => [
                    'type' => 'string',
                    'description' => 'Client identifier. Always "synaplan-desktop" for the official client.',
                ],
                'status' => [
                    'type' => 'string',
                    'description' => 'Free-form device status hint (e.g. "idle", "busy").',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    /**
     * The frozen `agent_report_result` argument schema (protocol 1, DS18).
     * Public for the contract-fixture test (invariant C9).
     *
     * @return array<string, mixed>
     */
    public static function agentReportResultSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'leaseToken' => [
                    'type' => 'string',
                    'description' => 'The leaseToken from the job returned by agent_checkin.',
                ],
                'status' => [
                    'type' => 'string',
                    'enum' => [DesktopJob::STATUS_SUCCEEDED, DesktopJob::STATUS_FAILED],
                    'description' => 'Outcome of the job. A refused skill is a normal "failed".',
                ],
                'result' => [
                    'type' => ['object', 'null'],
                    'description' => 'Structured result payload (e.g. {fileIds:[...], summary:"..."}). Size-capped.',
                ],
                'errorCode' => [
                    'type' => ['string', 'null'],
                    'enum' => [...DesktopJobContract::ERROR_CODES, null],
                    'description' => 'Machine-readable failure reason when status is "failed".',
                ],
            ],
            'required' => ['leaseToken', 'status'],
            'additionalProperties' => false,
        ];
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
     * Mirrors the `ROLE_ADMIN` check the HTTP admin endpoints use. Deliberately
     * not `User::isAdmin()`, which only looks at the internal user level and so
     * would lock out an admin whose role comes from the OIDC role mapping.
     */
    private static function hasAdminRole(User $user): bool
    {
        return \in_array('ROLE_ADMIN', $user->getRoles(), true);
    }

    /**
     * @return \Closure(string, ?string, int, ?string, ?string, int): array<string, mixed>
     */
    private function recentErrorsHandler(User $user): \Closure
    {
        return function (
            string $mode = 'summary',
            ?string $level = null,
            int $since_minutes = 60,
            ?string $query = null,
            ?string $request_id = null,
            int $limit = 50,
        ) use ($user): array {
            // Defence in depth: the tool is only registered for admins, but a
            // handler must never assume its own visibility gate held.
            if (!self::hasAdminRole($user)) {
                throw new ToolCallException('recent_errors requires an admin account.');
            }

            $sinceMinutes = max(1, min(10080, $since_minutes)); // cap at 7 days
            $sinceTs = time() - $sinceMinutes * 60;

            if ('recent' === $mode) {
                $level = null !== $level && \in_array($level, EventRingStore::LEVELS, true) ? $level : null;
                $query = null !== $query && '' !== trim($query) ? $query : null;
                $requestId = null !== $request_id && '' !== trim($request_id) ? $request_id : null;

                $events = $this->eventRing->recent($level, $sinceTs, $query, $requestId, max(1, min(200, $limit)));

                return [
                    'mode' => 'recent',
                    'window_minutes' => $sinceMinutes,
                    'total' => \count($events),
                    'events' => $events,
                ];
            }

            return [
                'mode' => 'summary',
                'window_minutes' => $sinceMinutes,
                'summary' => $this->eventRing->summary($sinceTs),
            ];
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
            $rows = $this->chatRepository->findByUserWithMessageCount(
                (int) $user->getId(),
                max(1, min(200, $limit)),
            );

            return [
                'total' => \count($rows),
                'chats' => array_map(static fn (array $row): array => [
                    'id' => $row['chat']->getId(),
                    'title' => $row['chat']->getTitle() ?? 'New Chat',
                    'source' => $row['chat']->getSource(),
                    'created_at' => $row['chat']->getCreatedAt()->format('c'),
                    'updated_at' => $row['chat']->getUpdatedAt()->format('c'),
                    'message_count' => $row['messageCount'],
                ], $rows),
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
                throw new ToolCallException('Chat not found.');
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
                'messages' => array_map(fn (Message $m): array => [
                    'id' => $m->getId(),
                    'role' => 'IN' === $m->getDirection() ? 'user' : 'assistant',
                    'text' => null !== $this->mediaTextRenderer
                        ? $this->mediaTextRenderer->forUser($m->getText(), $m->getLanguage(), $user->getLocale())
                        : GeneratedMediaTextRenderer::renderModel((string) $m->getText()),
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
     * @return \Closure(): array<string, mixed>
     */
    private function listAssistantsHandler(User $user, ?DesktopDevice $device): \Closure
    {
        return function () use ($user, $device): array {
            if (null === $this->assistantAliases) {
                return ['total' => 0, 'assistants' => []];
            }
            $assistants = $this->assistantAliases->listAssistants($user, self::assistantEventKinds($device));

            return ['total' => \count($assistants), 'assistants' => $assistants];
        };
    }

    /**
     * Event kinds an MCP session may address: `mcp` always, `desktop` only
     * when the session belongs to a paired Synaplan Desktop device.
     *
     * @return list<string>
     */
    private static function assistantEventKinds(?DesktopDevice $device): array
    {
        $kinds = [AssistantAliasResolver::EVENT_MCP];
        if ($device instanceof DesktopDevice) {
            $kinds[] = AssistantAliasResolver::EVENT_DESKTOP;
        }

        return $kinds;
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

            return self::truncateResource($file->getFileText());
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
     * @return \Closure(string, ?int, string|int|null): array<string, mixed>
     */
    private function chatHandler(User $user, ?DesktopDevice $device): \Closure
    {
        return function (string $message, ?int $chat_id = null, string|int|null $agentId = null) use ($user, $device): array {
            if (!($this->rateLimit->checkLimit($user, 'MESSAGES')['allowed'] ?? false)) {
                throw new ToolCallException('Rate limit exceeded for chat messages.');
            }

            // Resolve the pin before anything is persisted so an unusable
            // assistant yields a clear error instead of a stray chat turn.
            $options = [];
            if (null !== $agentId && '' !== (string) $agentId) {
                $agent = $this->assistantAliases?->resolveUsable($user, $agentId, self::assistantEventKinds($device));
                if (null === $agent?->getId()) {
                    throw new ToolCallException(sprintf('Assistant "%s" is not available: it must be published, allow connected apps and be usable by you.', (string) $agentId));
                }
                $options['agentId'] = $agent->getId();
            }

            $userId = (int) $user->getId();
            // Persist into a Chat so MCP conversations show up in list_chats /
            // get_messages and thread correctly (pipeline history uses chatId).
            $chat = $this->resolveChat($user, $chat_id, $message);
            $trackingId = time();

            $incoming = (new Message())
                ->setUserId($userId)
                ->setChat($chat)
                ->setTrackingId($trackingId)
                ->setProviderIndex('MCP')
                ->setUnixTimestamp(time())
                ->setDateTime(date('YmdHis'))
                ->setMessageType('API')
                ->setFile(0)
                ->setTopic('CHAT')
                ->setLanguage('en')
                ->setText($message)
                ->setDirection('IN')
                ->setStatus('processing');

            $this->em->persist($incoming);
            $this->em->flush();

            $result = $this->messageProcessor->process($incoming, $options);

            if (!($result['success'] ?? false)) {
                throw new ToolCallException($result['error'] ?? 'Message processing failed.');
            }

            $response = $result['response'] ?? [];
            $answer = $response['content'] ?? '';
            $meta = \is_array($response['metadata'] ?? null) ? $response['metadata'] : [];
            $classification = $result['classification'] ?? [];

            $incoming->setStatus('complete');

            $outgoing = (new Message())
                ->setUserId($userId)
                ->setChat($chat)
                ->setTrackingId($trackingId)
                ->setProviderIndex('MCP')
                ->setUnixTimestamp(time())
                ->setDateTime(date('YmdHis'))
                ->setMessageType('API')
                ->setFile(0)
                ->setTopic((string) ($classification['topic'] ?? 'CHAT'))
                ->setLanguage((string) ($classification['language'] ?? 'en'))
                ->setText($answer)
                ->setDirection('OUT')
                ->setStatus('complete');

            $this->em->persist($outgoing);
            $chat->updateTimestamp();
            $this->em->flush();

            // Rolling-summary refresh after the OUT persist (channel parity):
            // MCP conversations thread through Chats like every other channel.
            if (null !== $chat->getId()) {
                $this->summaryRefreshDispatcher->dispatch((int) $chat->getId(), $userId);
            }

            $this->recordChatUsage($user, $message, $answer, $meta);

            return [
                'success' => true,
                'answer' => null !== $this->mediaTextRenderer
                    ? $this->mediaTextRenderer->forUser(
                        $answer,
                        (string) ($classification['language'] ?? 'en'),
                        $user->getLocale(),
                    )
                    : GeneratedMediaTextRenderer::renderModel($answer),
                'chat_id' => $chat->getId(),
                'topic' => $classification['topic'] ?? null,
                'intent' => $classification['intent'] ?? null,
                'files' => \is_array($meta['files'] ?? null)
                    ? $meta['files']
                    : (isset($meta['file']) ? [$meta['file']] : []),
            ];
        };
    }

    /**
     * Reuse the caller's chat (when `chat_id` is given and owned) or create a new one.
     */
    private function resolveChat(User $user, ?int $chatId, string $firstMessage): Chat
    {
        if (null !== $chatId) {
            $chat = $this->chatRepository->find($chatId);
            if ($chat instanceof Chat && $chat->getUserId() === (int) $user->getId()) {
                return $chat;
            }

            throw new ToolCallException('Chat not found.');
        }

        $chat = (new Chat())
            ->setUserId((int) $user->getId())
            ->setSource('mcp');

        $title = mb_substr(trim($firstMessage), 0, 40);
        if ('' !== $title) {
            $chat->setTitle($title);
        }

        $this->em->persist($chat);
        $this->em->flush();

        return $chat;
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function recordChatUsage(User $user, string $input, string $answer, array $meta): void
    {
        $this->rateLimit->recordUsage($user, 'MESSAGES', FacadeMeteredChat::messages($meta, 'MCP', $input, $answer));

        $mediaAction = match ($meta['media_type'] ?? null) {
            'image' => 'IMAGES',
            'video' => 'VIDEOS',
            'audio' => 'AUDIOS',
            default => null,
        };

        if (null !== $mediaAction) {
            $this->rateLimit->recordUsage($user, $mediaAction, [
                'provider' => $meta['provider'] ?? 'unknown',
                'model' => $meta['model'] ?? 'unknown',
                'model_id' => $meta['model_id'] ?? null,
                'source' => 'MCP',
                'media_usage' => $meta['media_usage'] ?? [],
            ]);
        }
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
                throw new ToolCallException('content must not be empty.');
            }

            // Ingestion runs the embedding pipeline (provider cost) — gate it.
            if (!($this->rateLimit->checkLimit($user, 'FILE_ANALYSIS')['allowed'] ?? false)) {
                throw new ToolCallException('Rate limit exceeded for file ingestion.');
            }

            $userId = (int) $user->getId();
            $size = \strlen($content);

            try {
                $this->storageQuota->checkStorageLimit($user, $size);
            } catch (\RuntimeException $e) {
                throw new ToolCallException($e->getMessage());
            }

            $title = '' !== trim($name) ? trim($name) : 'mcp-document';

            $stored = $this->fileStorage->storeRawContent($content, $userId, $title.'.txt', 'text/plain');
            if (!$stored['success']) {
                throw new ToolCallException($stored['error'] ?? 'Failed to store content.');
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
                // Roll back so a failed ingest does not leave an un-searchable file
                // consuming storage quota.
                $this->fileStorage->deleteFile((string) $stored['path']);
                $this->em->remove($file);
                $this->em->flush();

                throw new ToolCallException($vector['error'] ?? 'Vectorization failed.');
            }

            $file->markVectorized();
            $this->em->flush();

            $this->rateLimit->recordFileAnalysisOnce($user, (int) $file->getId(), [
                'source' => 'MCP',
                'filename' => $title,
            ]);

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
            // Creating a memory embeds the value (provider cost) — gate it.
            // createMemory() records the EMBEDDINGS usage internally.
            if (!($this->rateLimit->checkLimit($user, 'EMBEDDINGS')['allowed'] ?? false)) {
                throw new ToolCallException('Rate limit exceeded for embeddings.');
            }

            if (!$this->memoryService->isAvailable()) {
                throw new ToolCallException('Memory service is currently unavailable.');
            }

            try {
                $memory = $this->memoryService->createMemory($user, $category, $key, $value, 'user_created');
            } catch (\InvalidArgumentException $e) {
                throw new ToolCallException($e->getMessage());
            } catch (MemoryServiceUnavailableException) {
                throw new ToolCallException('Memory service is currently unavailable.');
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
    private static function recentErrorsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'mode' => [
                    'type' => 'string',
                    'enum' => ['summary', 'recent'],
                    'default' => 'summary',
                    'description' => 'summary = aggregate counts by level/event/route; recent = the newest individual events.',
                ],
                'level' => [
                    'type' => ['string', 'null'],
                    'description' => 'Filter recent events by exact level (debug, info, notice, warning, error, critical, alert, emergency).',
                ],
                'since_minutes' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10080,
                    'default' => 60,
                    'description' => 'Look back this many minutes (max 7 days).',
                ],
                'query' => [
                    'type' => ['string', 'null'],
                    'description' => 'Case-insensitive substring match across event/message/exception/route/provider/model (recent mode).',
                ],
                'request_id' => [
                    'type' => ['string', 'null'],
                    'description' => 'Filter recent events by correlation id (the X-Request-Id a user may report).',
                ],
                'limit' => [
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 200,
                    'default' => 50,
                    'description' => 'Maximum number of events to return in recent mode (1-200).',
                ],
            ],
            'additionalProperties' => false,
        ];
    }

    private static function truncateResource(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_RESOURCE_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_RESOURCE_CHARS)."\n\n[… truncated …]";
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
