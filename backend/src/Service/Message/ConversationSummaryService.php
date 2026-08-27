<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\ChatSummaryRepository;
use App\Repository\MessageRepository;
use App\Service\ModelConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Rolling conversation summary — read on the hot path, written off it.
 *
 * Hot path ({@see buildRollingContext()}): never calls an AI model. It reads
 * the stored summary for the chat (if any), keeps the newest turns verbatim,
 * and — when the store is one turn behind — appends the small raw gap so no
 * context is lost while the worker catches up.
 *
 * Worker path ({@see refresh()}): runs after the turn is persisted. Folds only
 * the newly aged-out messages into the previous summary (or bootstraps from
 * scratch on the first refresh). Uses the SUMMARIZE model default.
 *
 * Storage is read-through: Redis is the hot cache, `BCHATSUMMARIES` the
 * durable layer. A cache miss falls back to the DB row and re-warms Redis, so
 * slow channels (email, WhatsApp) keep continuity long after the cache TTL.
 *
 * Never throws into the chat turn: on any failure the hot path returns
 * {@see RollingSummaryResult::notApplied()} and the caller keeps its normal
 * history window.
 */
final readonly class ConversationSummaryService
{
    /**
     * Skip summarizing when the older span carries too little text — an AI call
     * would not be worth it and dropping that little from the window is harmless.
     */
    private const MIN_OLDER_CHARS = 500;

    /**
     * Cap on the raw gap appended on the hot path when the store is one turn
     * behind. Keeps a lagging worker from bloating the system prompt.
     */
    private const GAP_CHAR_CAP = 3000;

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private ConversationSummaryConfigService $config,
        private MessageRepository $messageRepository,
        private ChatSummaryRepository $chatSummaryRepository,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Hot-path read: inject the stored summary, never call an AI model.
     *
     * @param Message[] $recentWindow      recent window already loaded by the caller, chronological (oldest first)
     * @param int       $totalMessageCount total messages in the chat (cheap COUNT)
     */
    public function buildRollingContext(array $recentWindow, int $totalMessageCount, ?int $userId, ?int $chatId): RollingSummaryResult
    {
        if (!$this->config->isEnabled() || null === $chatId || [] === $recentWindow) {
            return RollingSummaryResult::notApplied($recentWindow);
        }

        $recentWindow = array_values($recentWindow);
        $tail = $this->recentTail($recentWindow, $this->config->getRecentVerbatimChars());
        $tailCount = count($tail);

        $olderCount = $totalMessageCount - $tailCount;
        if ($olderCount <= 0) {
            return RollingSummaryResult::notApplied($recentWindow);
        }

        $olderLastId = $this->resolveOlderLastId($recentWindow, $tailCount, $userId, $chatId);
        if (null === $olderLastId) {
            return RollingSummaryResult::notApplied($recentWindow);
        }

        $stored = $this->readStored($chatId);
        if (null === $stored) {
            // First long-chat turn (or cache eviction): answer without a summary
            // this turn. The async refresh after the flush fills the store for
            // the next one — never block time-to-first-token on a cold start.
            return RollingSummaryResult::notApplied($recentWindow);
        }

        $summary = $stored['summary'];
        if ($stored['upToMessageId'] < $olderLastId) {
            // Worker is one turn behind. Append the small raw gap so those
            // messages aren't invisible until the fold lands.
            $gap = $this->messageRepository->findMessagesBetween(
                $userId ?? 0,
                $chatId,
                $stored['upToMessageId'],
                $olderLastId,
            );
            if ([] !== $gap) {
                $summary = $this->appendRawGap($summary, $gap);
            }
        }

        return new RollingSummaryResult(true, $summary, $tail, $olderCount);
    }

    /**
     * Worker entry point: refresh the stored summary for a chat.
     *
     * Incremental when a previous summary exists (fold only newly aged-out
     * messages). Bootstrap from scratch otherwise. Safe to call on every turn —
     * no-ops when the store already covers the current older span.
     *
     * @return bool true when a new summary was written
     */
    public function refresh(int $chatId, int $userId): bool
    {
        if (!$this->config->isEnabled()) {
            return false;
        }

        $fullHistory = array_values($this->messageRepository->findAllByChatId($userId, $chatId));
        if ([] === $fullHistory) {
            return false;
        }

        // Match the hot path's window contract: MessageProcessor loads at most
        // HISTORY_MAX_MESSAGES, then recentTail trims that by char budget.
        // Applying only the char budget to the FULL history would leave short
        // message chats unsummarized forever (everything fits in 8000 chars).
        $window = count($fullHistory) > MessageProcessor::HISTORY_MAX_MESSAGES
            ? array_slice($fullHistory, -MessageProcessor::HISTORY_MAX_MESSAGES)
            : $fullHistory;
        $tail = $this->recentTail($window, $this->config->getRecentVerbatimChars());
        $olderCount = count($fullHistory) - count($tail);
        if ($olderCount <= 0) {
            return false;
        }

        /** @var list<Message> $older */
        $older = array_slice($fullHistory, 0, $olderCount);
        $olderLastId = (int) $older[array_key_last($older)]->getId();

        $olderChars = 0;
        foreach ($older as $msg) {
            $olderChars += $this->messageLength($msg);
        }
        if ($olderChars < self::MIN_OLDER_CHARS) {
            return false;
        }

        $stored = $this->readStored($chatId);
        if (null !== $stored && $stored['upToMessageId'] >= $olderLastId) {
            return false;
        }

        if (null !== $stored && $stored['upToMessageId'] > 0) {
            $newMessages = array_values(array_filter(
                $older,
                static fn (Message $m): bool => (int) $m->getId() > $stored['upToMessageId'],
            ));
            if ([] === $newMessages) {
                return false;
            }

            $summary = $this->foldIncremental($stored['summary'], $newMessages, $userId, $chatId);
        } else {
            $summary = $this->bootstrap($older, $userId, $chatId);
        }

        if (null === $summary || '' === trim($summary)) {
            return false;
        }

        $this->writeStored($chatId, $userId, $summary, $olderLastId, $olderCount);

        return true;
    }

    /**
     * @param Message[] $recentWindow
     */
    private function resolveOlderLastId(array $recentWindow, int $tailCount, ?int $userId, int $chatId): ?int
    {
        if ($tailCount < count($recentWindow)) {
            return (int) $recentWindow[count($recentWindow) - $tailCount - 1]->getId();
        }

        return $this->messageRepository->findIdBefore(
            $userId ?? 0,
            $chatId,
            (int) $recentWindow[0]->getId(),
            $recentWindow[0]->getUnixTimestamp(),
        );
    }

    /**
     * @param list<Message> $older
     */
    private function bootstrap(array $older, int $userId, int $chatId): ?string
    {
        $maxSource = $this->config->getMaxSourceMessages();
        $source = count($older) > $maxSource ? array_slice($older, -$maxSource) : $older;

        return $this->callSummarizer(
            ConversationSummaryPrompts::bootstrapSystemPrompt($this->config->getSummaryMaxChars()),
            ConversationSummaryPrompts::bootstrapUserContent($source, $this->config->getTiers()),
            $userId,
            $chatId,
            'bootstrap',
        );
    }

    /**
     * @param list<Message> $newMessages
     */
    private function foldIncremental(string $previousSummary, array $newMessages, int $userId, int $chatId): ?string
    {
        return $this->callSummarizer(
            ConversationSummaryPrompts::incrementalSystemPrompt($this->config->getSummaryMaxChars()),
            ConversationSummaryPrompts::incrementalUserContent($previousSummary, $newMessages),
            $userId,
            $chatId,
            'incremental',
        );
    }

    private function callSummarizer(string $systemPrompt, string $userContent, int $userId, int $chatId, string $mode): ?string
    {
        $summaryMax = $this->config->getSummaryMaxChars();

        try {
            $modelConfig = $this->modelConfigService->getSummaryModelConfig($userId);

            $response = $this->aiFacade->chat([
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userContent],
            ], $userId, [
                'provider' => $modelConfig['provider'] ?? null,
                'model' => $modelConfig['model'] ?? null,
                'temperature' => 0.2,
                'max_tokens' => ConversationSummaryPrompts::tokenBudget($summaryMax),
            ]);

            $summary = $this->clip(trim((string) ($response['content'] ?? '')), $summaryMax);
            if ('' === $summary) {
                return null;
            }

            $this->logger->info('ConversationSummaryService: refreshed rolling summary', [
                'chat_id' => $chatId,
                'mode' => $mode,
                'summary_chars' => mb_strlen($summary),
                'provider' => $modelConfig['provider'] ?? null,
                'model' => $modelConfig['model'] ?? null,
            ]);

            return $summary;
        } catch (\Throwable $e) {
            $this->logger->warning('ConversationSummaryService: refresh failed', [
                'chat_id' => $chatId,
                'mode' => $mode,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Read-through: Redis hit wins; on a miss the durable DB row (written by
     * a previous worker refresh, possibly days ago) is loaded, validated
     * against the current config fingerprint, and re-warmed into Redis.
     *
     * @return array{summary: string, upToMessageId: int, summarizedCount: int}|null
     */
    private function readStored(int $chatId): ?array
    {
        $item = $this->cache->getItem($this->storeKey($chatId));
        if ($item->isHit()) {
            $cached = $this->validateStored($item->get());
            if (null !== $cached) {
                return $cached;
            }
            // Invalid/legacy shape: purge it so subsequent reads don't keep
            // hitting the bad entry and can re-warm from the durable row.
            $this->cache->deleteItem($this->storeKey($chatId));
        }

        return $this->readDurable($chatId);
    }

    /**
     * @return array{summary: string, upToMessageId: int, summarizedCount: int}|null
     */
    private function readDurable(int $chatId): ?array
    {
        try {
            $row = $this->chatSummaryRepository->findOneByChatId($chatId);
        } catch (\Throwable $e) {
            $this->logger->warning('ConversationSummaryService: durable read failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (null === $row || $row->getFingerprint() !== $this->configFingerprint()) {
            // Absent, or written under different summary settings — treat as
            // cold so the worker re-bootstraps under the current config.
            return null;
        }

        $stored = $this->validateStored([
            'summary' => $row->getSummary(),
            'upToMessageId' => $row->getUpToMessageId(),
            'summarizedCount' => $row->getSummarizedCount(),
        ]);
        if (null === $stored) {
            return null;
        }

        $this->writeCache($chatId, $stored['summary'], $stored['upToMessageId'], $stored['summarizedCount']);

        return $stored;
    }

    /**
     * @return array{summary: string, upToMessageId: int, summarizedCount: int}|null
     */
    private function validateStored(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }

        $summary = $raw['summary'] ?? null;
        $upTo = $raw['upToMessageId'] ?? null;
        if (!is_string($summary) || '' === $summary || !is_int($upTo) || $upTo <= 0) {
            return null;
        }

        return [
            'summary' => $summary,
            'upToMessageId' => $upTo,
            'summarizedCount' => is_int($raw['summarizedCount'] ?? null) ? $raw['summarizedCount'] : 0,
        ];
    }

    private function writeStored(int $chatId, int $userId, string $summary, int $upToMessageId, int $summarizedCount): void
    {
        // Durable layer first: a DB failure must not leave the cache claiming
        // more coverage than the row has. Fail-open — the Redis copy still
        // carries the summary for the TTL window, like before this table.
        try {
            $this->chatSummaryRepository->upsert(
                $chatId,
                $userId,
                $summary,
                $upToMessageId,
                $summarizedCount,
                $this->configFingerprint(),
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ConversationSummaryService: durable write failed', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->writeCache($chatId, $summary, $upToMessageId, $summarizedCount);
    }

    private function writeCache(int $chatId, string $summary, int $upToMessageId, int $summarizedCount): void
    {
        $item = $this->cache->getItem($this->storeKey($chatId));
        $item->set([
            'summary' => $summary,
            'upToMessageId' => $upToMessageId,
            'summarizedCount' => $summarizedCount,
        ]);
        $item->expiresAfter($this->config->getCacheTtl());
        $this->cache->save($item);
    }

    /**
     * Config knobs that change the summary shape, folded into a fingerprint.
     * Used in both the cache key and the durable row so a settings change
     * invalidates the previous store and forces a re-bootstrap.
     */
    private function configFingerprint(): string
    {
        return md5(implode(':', [
            $this->config->getSummaryMaxChars(),
            $this->config->getTiers(),
            $this->config->getRecentVerbatimChars(),
            'v2-async',
        ]));
    }

    private function storeKey(int $chatId): string
    {
        return sprintf('conv_summary.chat.%d.%s', $chatId, $this->configFingerprint());
    }

    /**
     * @param list<Message> $gap
     */
    private function appendRawGap(string $summary, array $gap): string
    {
        $lines = ["\n\n## Recent (not yet condensed)"];
        foreach ($gap as $msg) {
            $lines[] = ConversationSummaryPrompts::renderMessage($msg);
        }

        return $this->clip($summary.implode("\n", $lines), $this->config->getSummaryMaxChars() + self::GAP_CHAR_CAP);
    }

    /**
     * Newest messages within the verbatim char budget, chronological (oldest first).
     *
     * @param Message[] $history chronological (oldest first)
     *
     * @return list<Message>
     */
    private function recentTail(array $history, int $budget): array
    {
        $reversed = [];
        $chars = 0;
        foreach (array_reverse(array_values($history)) as $msg) {
            $len = $this->messageLength($msg);
            if (count($reversed) > 0 && ($chars + $len) > $budget) {
                break;
            }
            $reversed[] = $msg;
            $chars += $len;
        }

        return array_reverse($reversed);
    }

    private function messageLength(Message $msg): int
    {
        return mb_strlen((string) $msg->getText()) + mb_strlen($msg->getFileText());
    }

    private function clip(string $value, int $maxChars): string
    {
        if (mb_strlen($value) <= $maxChars) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, $maxChars)).'…';
    }
}
