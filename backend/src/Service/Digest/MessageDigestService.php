<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\Schema\MessageDigestSchema;
use App\AI\StructuredOutput\StructuredOutputConfig;
use App\Entity\Message;
use App\Entity\MessageDigest;
use App\Entity\User;
use App\Repository\MessageDigestRepository;
use App\Repository\PromptRepository;
use App\Service\Memory\MemoryEmbeddingModelResolver;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\VectorSearch\QdrantClientInterface;
use Psr\Log\LoggerInterface;

/**
 * Digests one batch of messages into searchable one-liners.
 *
 * Out-of-band sibling of {@see \App\Service\MemoryExtractionService}: the
 * daily digest job (never the chat hot path) hands a batch of a user's
 * messages to the memory model, which picks the KEY messages and writes one
 * retrieval-friendly title per message. Rows go to MariaDB (authoritative)
 * and are mirrored into the Qdrant digests collection for vector search.
 */
final readonly class MessageDigestService
{
    private const PROMPT_TOPIC = 'tools:message_digest';
    private const MIN_TITLE_CHARS = 8;
    /** Matches the "max 200 characters" rule in the digest prompts (DB column allows 500 as headroom). */
    private const MAX_TITLE_CHARS = 200;
    private const MESSAGE_CLIP_CHARS = 1500;
    private const FILE_TEXT_CLIP_CHARS = 1000;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private RateLimitService $rateLimitService,
        private PromptRepository $promptRepository,
        private MessageDigestRepository $digestRepository,
        private QdrantClientInterface $qdrantClient,
        private MemoryEmbeddingModelResolver $embeddingResolver,
        private LoggerInterface $logger,
        private StructuredOutputConfig $structuredOutputConfig,
    ) {
    }

    /**
     * Digest one batch of messages for a user.
     *
     * Already-digested messages are dropped before the model sees them, and
     * existing digest titles from the same chats are provided as dedup
     * context, so re-running over the same range is idempotent and cheap.
     *
     * @param list<Message> $messages
     *
     * @return array{scanned: int, created: int, proposals: list<array{title: string, message_id: int}>}
     */
    public function digestBatch(User $user, array $messages, bool $dryRun = false): array
    {
        $messages = array_values(array_filter(
            $messages,
            static fn (Message $m): bool => null !== $m->getId()
        ));

        if ([] === $messages) {
            return ['scanned' => 0, 'created' => 0, 'proposals' => []];
        }

        $messageIds = array_map(static fn (Message $m): int => (int) $m->getId(), $messages);
        $alreadyDigested = $this->digestRepository->findDigestedMessageIds($user->getId(), $messageIds);
        $pending = array_values(array_filter(
            $messages,
            static fn (Message $m): bool => !in_array((int) $m->getId(), $alreadyDigested, true)
        ));

        if ([] === $pending) {
            return ['scanned' => count($messages), 'created' => 0, 'proposals' => []];
        }

        $chatIds = array_values(array_unique(array_filter(array_map(
            static fn (Message $m): int => (int) $m->getChatId(),
            $pending
        ))));
        $existingTitles = $this->digestRepository->findTitlesForChats($user->getId(), $chatIds);

        $proposals = $this->extractDigestsViaAi($user, $pending, $existingTitles);

        $created = 0;
        if (!$dryRun) {
            $byId = [];
            foreach ($pending as $message) {
                $byId[(int) $message->getId()] = $message;
            }

            foreach ($proposals as $proposal) {
                $this->storeDigest($user, $byId[$proposal['message_id']], $proposal['title']);
                ++$created;
            }
        }

        return [
            'scanned' => count($messages),
            'created' => $created,
            'proposals' => $proposals,
        ];
    }

    /**
     * @param list<Message> $messages
     * @param list<string>  $existingTitles
     *
     * @return list<array{title: string, message_id: int}>
     */
    private function extractDigestsViaAi(User $user, array $messages, array $existingTitles): array
    {
        $batchText = '';
        foreach ($messages as $message) {
            $batchText .= $this->renderMessage($message)."\n";
        }

        $existingBlock = '';
        if ([] !== $existingTitles) {
            $existingBlock = "\nExisting digest titles from these conversations (do NOT duplicate):\n";
            foreach ($existingTitles as $title) {
                $existingBlock .= '- '.$title."\n";
            }
        }

        $userPrompt = <<<PROMPT
Message batch (each line starts with [#id direction channel date]):
{$batchText}{$existingBlock}
RESPONSE FORMAT (strict JSON, no markdown):
[
  {"title": "office rent letter to realtor about the increase of payments", "message_id": 1234}
]
Return [] or null if no message in this batch is worth indexing.
PROMPT;

        try {
            $modelConfig = $this->modelConfigService->getMemoryModelConfig($user->getId());

            $aiOptions = [
                'temperature' => 0.2,
                'model' => $modelConfig['model'],
                'provider' => $modelConfig['provider'],
            ];

            if ($this->structuredOutputConfig->isEnabled($user->getId())) {
                $aiOptions['structured_output'] = MessageDigestSchema::build();
            }

            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $this->getDigestPrompt()],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                $user->getId(),
                $aiOptions
            );

            $content = $response['content'] ?? '';

            $this->rateLimitService->recordUsage($user, 'MESSAGE_DIGEST', [
                'provider' => $response['provider'] ?? 'unknown',
                'model' => $response['model'] ?? 'unknown',
                'model_id' => $modelConfig['model_id'] ?? null,
                'usage' => $response['usage'] ?? [],
                'response_text' => $content,
                'input_text' => $userPrompt,
                'source' => 'DIGEST',
            ]);

            $validIds = array_map(static fn (Message $m): int => (int) $m->getId(), $messages);

            return $this->parseDigestsFromResponse($content, $validIds);
        } catch (\Throwable $e) {
            $this->logger->error('Message digest extraction failed', [
                'user_id' => $user->getId(),
                'batch_size' => count($messages),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Strict validation: only titles with a `message_id` that exists in the
     * current batch survive — an invented id would index a hallucination.
     *
     * @param list<int> $validMessageIds
     *
     * @return list<array{title: string, message_id: int}>
     */
    private function parseDigestsFromResponse(string $content, array $validMessageIds): array
    {
        $content = trim($content);

        if ('' === $content || 'null' === strtolower($content)) {
            return [];
        }

        if (1 === preg_match('/\[[\s\S]*\]/', $content, $matches)) {
            $jsonString = $matches[0];
        } else {
            $jsonString = $content;
        }

        try {
            $decoded = json_decode($jsonString, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            if (false !== stripos($content, 'null')) {
                return [];
            }

            $this->logger->warning('Failed to parse message digest JSON', [
                'content_preview' => substr($content, 0, 300),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        $validated = [];
        $seenIds = [];
        foreach ($decoded as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $title = $entry['title'] ?? null;
            $messageId = $entry['message_id'] ?? null;

            if (!is_string($title) || !is_numeric($messageId)) {
                continue;
            }

            $messageId = (int) $messageId;
            $title = trim($title);

            if (mb_strlen($title) < self::MIN_TITLE_CHARS) {
                continue;
            }
            if (mb_strlen($title) > self::MAX_TITLE_CHARS) {
                $title = mb_substr($title, 0, self::MAX_TITLE_CHARS);
            }

            if (!in_array($messageId, $validMessageIds, true)) {
                $this->logger->warning('Digest model invented a message_id, dropping entry', [
                    'message_id' => $messageId,
                ]);
                continue;
            }

            if (isset($seenIds[$messageId])) {
                continue;
            }
            $seenIds[$messageId] = true;

            $validated[] = ['title' => $title, 'message_id' => $messageId];
        }

        return $validated;
    }

    private function storeDigest(User $user, Message $message, string $title): void
    {
        $timestampMs = (int) floor(microtime(true) * 1000);
        $digestId = ($timestampMs * 1000) + random_int(0, 999);

        $digest = new MessageDigest();
        $digest->setId($digestId)
            ->setUserId($user->getId())
            ->setChatId((int) $message->getChatId())
            ->setMessageId((int) $message->getId())
            ->setTitle($title)
            ->setChannel(strtolower($message->getMessageType()))
            ->setSourceDate($message->getUnixTimestamp())
            ->setActive(true)
            ->setCreated(time());

        $this->digestRepository->upsert($digest);

        $this->mirrorToQdrant($user, $digest);
    }

    /**
     * The deterministic logical point id of a digest in the Qdrant digests
     * collection — shared by mirroring, deletion hygiene, and re-indexing so
     * a rebuilt point always overwrites its predecessor.
     */
    public static function qdrantPointId(int $userId, int $digestId): string
    {
        return sprintf('dig_%d_%d', $userId, $digestId);
    }

    /**
     * Vector mirror is best-effort: MariaDB is authoritative, and a Qdrant
     * outage must not lose the digest row (a later re-index can rebuild the
     * collection from the table). Public so `app:digest:reindex` can rebuild
     * the collection after an embedding-model change. Returns whether the
     * point was written.
     */
    public function mirrorToQdrant(User $user, MessageDigest $digest): bool
    {
        if (!$this->qdrantClient->isAvailable()) {
            $this->logger->warning('Qdrant unavailable, digest stored in DB only', [
                'digest_id' => $digest->getId(),
            ]);

            return false;
        }

        try {
            $embeddingConfig = $this->embeddingResolver->resolve();

            $embedResult = $this->aiFacade->embed($digest->getTitle(), $user->getId(), array_filter([
                'model' => $embeddingConfig['model'],
                'provider' => $embeddingConfig['provider'],
            ]));
            $embedding = $embedResult['embedding'];

            if (empty($embedding)) {
                throw new \RuntimeException('Failed to create digest embedding');
            }

            $this->rateLimitService->recordUsage($user, 'EMBEDDINGS', [
                'usage' => $embedResult['usage'],
                'provider' => $embeddingConfig['provider'] ?? 'unknown',
                'model' => $embeddingConfig['model'] ?? 'unknown',
                'model_id' => $embeddingConfig['model_id'],
                'input_text' => $digest->getTitle(),
                'source' => 'DIGEST_STORE',
            ]);

            $pointId = self::qdrantPointId($user->getId(), $digest->getId());

            $this->qdrantClient->upsertDigest($pointId, $embedding, [
                'user_id' => $digest->getUserId(),
                'chat_id' => $digest->getChatId(),
                'message_id' => $digest->getMessageId(),
                'title' => $digest->getTitle(),
                'channel' => $digest->getChannel(),
                'source_date' => $digest->getSourceDate(),
                'active' => $digest->isActive(),
                'embedding_model_id' => $embeddingConfig['model_id'],
                'embedding_provider' => $embeddingConfig['provider'],
                'embedding_model' => $embeddingConfig['model'],
                'vector_dim' => count($embedding),
                'indexed_at' => date(\DATE_ATOM),
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to mirror digest to Qdrant (DB row kept)', [
                'digest_id' => $digest->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    private function renderMessage(Message $message): string
    {
        $text = trim($message->getText());
        if (mb_strlen($text) > self::MESSAGE_CLIP_CHARS) {
            $text = mb_substr($text, 0, self::MESSAGE_CLIP_CHARS).'…';
        }

        $line = sprintf(
            '[#%d %s %s %s] %s',
            (int) $message->getId(),
            'IN' === $message->getDirection() ? 'user' : 'assistant',
            strtolower($message->getMessageType()),
            date('Y-m-d', $message->getUnixTimestamp()),
            $text
        );

        $fileText = trim($message->getFileText());
        if ('' !== $fileText) {
            if (mb_strlen($fileText) > self::FILE_TEXT_CLIP_CHARS) {
                $fileText = mb_substr($fileText, 0, self::FILE_TEXT_CLIP_CHARS).'…';
            }
            $line .= "\n  [attachment content] ".$fileText;
        }

        return $line;
    }

    /**
     * Digest system prompt from the database (seeded via PromptCatalog),
     * with an inline fallback for installs that have not re-seeded yet.
     */
    private function getDigestPrompt(): string
    {
        $prompt = $this->promptRepository->findOneBy([
            'topic' => self::PROMPT_TOPIC,
            'language' => 'en',
            'ownerId' => 0,
        ]);

        if (null !== $prompt) {
            return $prompt->getPrompt();
        }

        $this->logger->warning('Message digest prompt not found in DB, using fallback');

        return <<<'PROMPT'
You index a user's message history. Select ONLY the KEY messages of the batch (documents, decisions, important facts/dates/names — never small talk) and write one searchable title per message, in the language of the source message, max 200 characters.

RESPONSE FORMAT (strict JSON, no markdown):
[
  {"title": "office rent letter to realtor about the increase of payments", "message_id": 1234}
]
message_id MUST be an id from the batch. Return [] or null if nothing is worth indexing.
PROMPT;
    }
}
