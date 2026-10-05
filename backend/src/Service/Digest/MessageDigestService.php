<?php

declare(strict_types=1);

namespace App\Service\Digest;

use App\AI\Exception\ChatFailureClassifier;
use App\AI\Exception\ModelNotConfiguredException;
use App\AI\Service\AiFacade;
use App\AI\StructuredOutput\JsonResponseDecoder;
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
    /** Titles from the user's other chats, so a task repeated elsewhere is not indexed twice. */
    private const RECENT_TITLES_FOR_DEDUP = 30;

    /** The model answered, but the body was not a JSON list or null. */
    public const FAILURE_UNPARSABLE = 'unparsable';

    /**
     * CircuitBreaker fail-fast. The exception has no status or context, so
     * {@see ChatFailureClassifier} would report Unknown.
     */
    public const FAILURE_CIRCUIT_OPEN = 'circuit_open';

    /**
     * {@see ModelNotConfiguredException}. The classifier would report Unknown.
     */
    public const FAILURE_MODEL_NOT_CONFIGURED = 'model_not_configured';

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
        private JsonResponseDecoder $jsonDecoder = new JsonResponseDecoder(),
        private ChatFailureClassifier $failureClassifier = new ChatFailureClassifier(),
    ) {
    }

    /**
     * Digest one batch of messages for a user.
     *
     * Already-digested messages are dropped before the model sees them, and
     * existing digest titles from the same chats are provided as dedup
     * context, so re-running over the same range is idempotent and cheap.
     *
     * A parsed answer — `[]`, `null`, or a list that validates to nothing —
     * is a successful scan. A thrown provider error or an unparsable answer
     * sets `failed` and `failureReason` and leaves `scanned` at 0.
     *
     * `$pendingTitles` are titles a dry run has proposed in earlier batches;
     * they count as existing because a real run would have stored them.
     *
     * @param list<Message> $messages
     * @param list<string>  $pendingTitles
     *
     * @return array{scanned: int, created: int, proposals: list<array{title: string, message_id: int}>, failed: bool, failureReason: ?string}
     */
    public function digestBatch(User $user, array $messages, bool $dryRun = false, array $pendingTitles = []): array
    {
        $messages = array_values(array_filter(
            $messages,
            static fn (Message $m): bool => null !== $m->getId()
        ));

        if ([] === $messages) {
            return $this->outcome(0, 0, []);
        }

        $messageIds = array_map(static fn (Message $m): int => (int) $m->getId(), $messages);
        $alreadyDigested = $this->digestRepository->findDigestedMessageIds($user->getId(), $messageIds);
        $pending = array_values(array_filter(
            $messages,
            static fn (Message $m): bool => !in_array((int) $m->getId(), $alreadyDigested, true)
        ));

        if ([] === $pending) {
            return $this->outcome(count($messages), 0, []);
        }

        $chatIds = array_values(array_unique(array_filter(array_map(
            static fn (Message $m): int => (int) $m->getChatId(),
            $pending
        ))));
        $existingTitles = array_values(array_unique([
            ...$this->digestRepository->findTitlesForChats($user->getId(), $chatIds),
            ...$this->digestRepository->findRecentTitles($user->getId(), self::RECENT_TITLES_FOR_DEDUP),
            ...$pendingTitles,
        ]));

        $extraction = $this->extractDigestsViaAi($user, $pending, $existingTitles);
        if ($extraction['failed']) {
            return $this->outcome(0, 0, [], true, $extraction['failureReason']);
        }

        $proposals = $extraction['proposals'];
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

        return $this->outcome(count($messages), $created, $proposals);
    }

    /**
     * @param list<Message> $messages
     * @param list<string>  $existingTitles
     *
     * @return array{proposals: list<array{title: string, message_id: int}>, failed: bool, failureReason: ?string}
     */
    private function extractDigestsViaAi(User $user, array $messages, array $existingTitles): array
    {
        $batchText = '';
        foreach ($messages as $message) {
            $batchText .= $this->renderMessage($message)."\n";
        }

        $existingBlock = '';
        if ([] !== $existingTitles) {
            $existingBlock = "\nExisting digest titles from these and the user's recent conversations (do NOT duplicate):\n";
            foreach ($existingTitles as $title) {
                $existingBlock .= '- '.$title."\n";
            }
        }

        $userPrompt = <<<PROMPT
Message batch (each line starts with [#id direction channel date]):
{$batchText}{$existingBlock}
RESPONSE FORMAT (strict JSON, no markdown):
{"digests": [
  {"title": "office rent letter to realtor about the increase of payments", "message_id": 1234}
]}
Return {"digests": []} if no message in this batch is worth indexing.
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
            $parsed = $this->parseDigestsFromResponse($content, $validIds);
            if (null === $parsed) {
                return [
                    'proposals' => [],
                    'failed' => true,
                    'failureReason' => self::FAILURE_UNPARSABLE,
                ];
            }

            return [
                'proposals' => $parsed,
                'failed' => false,
                'failureReason' => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('Message digest extraction failed', [
                'user_id' => $user->getId(),
                'batch_size' => count($messages),
                'error' => $e->getMessage(),
            ]);

            return [
                'proposals' => [],
                'failed' => true,
                'failureReason' => $this->failureReasonFor($e),
            ];
        }
    }

    /**
     * Strict validation: only titles with a `message_id` that exists in the
     * current batch survive — an invented id would index a hallucination.
     *
     * @param list<int> $validMessageIds
     *
     * @return list<array{title: string, message_id: int}>|null null when the answer is not JSON
     */
    private function parseDigestsFromResponse(string $content, array $validMessageIds): ?array
    {
        $content = trim($content);

        if ('' === $content || 'null' === strtolower($content)) {
            return [];
        }

        $result = $this->jsonDecoder->decode($content);

        if (!$result->success || !is_array($result->data)) {
            $this->logger->warning('Failed to parse message digest JSON', [
                'content_preview' => substr($content, 0, 300),
                'error' => $result->errorReason,
            ]);

            return null;
        }

        // The schema path wraps the proposals under `digests` (a bare array
        // root is not expressible in structured output); the prose-instruction
        // fallback still returns them bare. A JSON null payload is an empty
        // scan, a lone digest object counts as a list of one, and anything
        // else that is not a list was not a digest answer.
        $decoded = array_key_exists('digests', $result->data) ? $result->data['digests'] : $result->data;
        if (null === $decoded) {
            return [];
        }
        if (is_array($decoded) && array_key_exists('message_id', $decoded)) {
            $decoded = [$decoded];
        }
        if (!is_array($decoded) || !array_is_list($decoded)) {
            $this->logger->warning('Failed to parse message digest JSON', [
                'content_preview' => substr($content, 0, 300),
                'error' => 'digest payload was not a list',
            ]);

            return null;
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

    /**
     * @param list<array{title: string, message_id: int}> $proposals
     *
     * @return array{scanned: int, created: int, proposals: list<array{title: string, message_id: int}>, failed: bool, failureReason: ?string}
     */
    private function outcome(int $scanned, int $created, array $proposals, bool $failed = false, ?string $failureReason = null): array
    {
        return [
            'scanned' => $scanned,
            'created' => $created,
            'proposals' => $proposals,
            'failed' => $failed,
            'failureReason' => $failureReason,
        ];
    }

    private function failureReasonFor(\Throwable $error): string
    {
        for ($current = $error; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof ModelNotConfiguredException) {
                return self::FAILURE_MODEL_NOT_CONFIGURED;
            }
            if ($this->isOpenCircuitBreakerMessage($current->getMessage())) {
                return self::FAILURE_CIRCUIT_OPEN;
            }
        }

        return $this->failureClassifier->classify($error)->value;
    }

    /**
     * CircuitBreaker fail-fast throws ProviderException with no HTTP status
     * and no context. Both strings are that class's own messages: the open
     * state, and the half-open overflow which sets the breaker open first.
     */
    private function isOpenCircuitBreakerMessage(string $message): bool
    {
        return str_contains($message, 'circuit breaker is OPEN')
            || str_contains($message, 'Service temporarily unavailable (too many test attempts)');
    }

    private function storeDigest(User $user, Message $message, string $title): void
    {
        $digest = new MessageDigest();
        $digest->setUserId($user->getId())
            ->setChatId((int) $message->getChatId())
            ->setMessageId((int) $message->getId())
            ->setTitle($title)
            ->setChannel(strtolower($message->getMessageType()))
            ->setSourceDate($message->getUnixTimestamp())
            ->setActive(true)
            ->setCreated(time());

        $storedId = $this->digestRepository->upsert($digest, self::nextDigestId(...));
        $digest->setId($storedId);

        $this->mirrorToQdrant($user, $digest);
    }

    /**
     * Millisecond timestamp plus a 0–999 suffix. Two calls in the same
     * millisecond can still collide; {@see MessageDigestRepository::upsert()}
     * retries with a new value instead of rewriting the other row.
     */
    private static function nextDigestId(): int
    {
        $timestampMs = (int) floor(microtime(true) * 1000);

        return ($timestampMs * 1000) + random_int(0, 999);
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
     * Inverse of {@see qdrantPointId()}. Null when the string is not a digest
     * point for this user.
     */
    public static function digestIdFromPointId(int $userId, string $pointId): ?int
    {
        $prefix = sprintf('dig_%d_', $userId);
        if (!str_starts_with($pointId, $prefix)) {
            return null;
        }

        $suffix = substr($pointId, strlen($prefix));
        if ('' === $suffix || !ctype_digit($suffix)) {
            return null;
        }

        return (int) $suffix;
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
                'digest_id' => $digest->getId(),
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
You index a user's message history. Select ONLY the KEY messages of the batch (documents with their content, decisions, important facts/dates/names — never small talk, requests to the assistant or notes that a file was created) and write one searchable title per message, in the language of the source message, max 200 characters.

RESPONSE FORMAT (strict JSON, no markdown):
{"digests": [
  {"title": "office rent letter to realtor about the increase of payments", "message_id": 1234}
]}
message_id MUST be an id from the batch. Return {"digests": []} if nothing is worth indexing.
PROMPT;
    }
}
