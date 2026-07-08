<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\ModelConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Builds a rolling, tiered, condensing summary of a conversation so long chats
 * keep their topic, the user's position, decisions and any external results
 * discussed — while the combined context (verbatim recent turns + summary)
 * stays inside a 10 000-15 000 character memory window.
 *
 * The most recent turns are kept verbatim; older turns are condensed with a
 * *gradient*: the oldest segment is compressed the hardest, the segment nearest
 * the verbatim window the least. The condensing model is configurable and
 * defaults to the sorting (SORT) model.
 *
 * Never throws into the chat turn: on any failure it returns
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

    public function __construct(
        private AiFacade $aiFacade,
        private ModelConfigService $modelConfigService,
        private ConversationSummaryConfigService $config,
        private MessageRepository $messageRepository,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Build the rolling context WITHOUT loading the whole chat up front.
     *
     * The caller passes the recent window it already loaded for classification
     * ({@see MessageRepository::findChatHistory()}) plus a cheap total message
     * count. The verbatim tail is derived from that window (it is always the
     * newest messages), and the full history is only queried on a genuine cache
     * miss — so long chats that hit the cache pay a single COUNT per turn, not a
     * full-history hydration (PR #1282 review, point 1).
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
        $recentBudget = $this->config->getRecentVerbatimChars();

        // Verbatim tail = newest messages within the char budget. Always a subset
        // of the recent window, so no full-history query is needed to compute it.
        $tail = $this->recentTail($recentWindow, $recentBudget);
        $tailCount = count($tail);

        // Messages before the tail (the summarization source). Derived from the
        // cheap COUNT — never from loading the whole history.
        $olderCount = $totalMessageCount - $tailCount;
        if ($olderCount <= 0) {
            return RollingSummaryResult::notApplied($recentWindow);
        }

        // Newest "older" message id (the per-span cache key). When the tail did
        // not consume the entire loaded window the boundary message is already in
        // memory, so a cache hit needs NO full-history query.
        $olderLastId = $tailCount < count($recentWindow)
            ? (int) $recentWindow[count($recentWindow) - $tailCount - 1]->getId()
            : null;

        if (null !== $olderLastId) {
            $cached = $this->readCachedSummary($chatId, $olderLastId, $olderCount);
            if (null !== $cached) {
                return new RollingSummaryResult(true, $cached, $tail, $olderCount);
            }
        }

        // Cache miss (or the boundary was outside the loaded window): load the
        // full history once to build the summary source.
        $fullHistory = array_values($this->messageRepository->findAllByChatId($userId ?? 0, $chatId));
        $tail = $this->recentTail($fullHistory, $recentBudget);
        $olderCount = count($fullHistory) - count($tail);
        if ($olderCount <= 0) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        /** @var list<Message> $older */
        $older = array_slice($fullHistory, 0, $olderCount);
        $olderLastId = (int) $older[array_key_last($older)]->getId();

        $olderChars = 0;
        foreach ($older as $msg) {
            $olderChars += $this->messageLength($msg);
        }
        if ($olderChars < self::MIN_OLDER_CHARS) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        $summary = $this->summarizeOlder($older, $olderLastId, $olderCount, $userId, $chatId);
        if (null === $summary || '' === trim($summary)) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        return new RollingSummaryResult(true, $summary, $tail, $olderCount);
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

    /**
     * Summarize the older span with gradient compression, storing it in the
     * per-span cache. {@see $olderCount} is the UNCAPPED older-span size so the
     * key matches the cheap cache-hit lookup in {@see buildRollingContext()}.
     *
     * @param list<Message> $older
     */
    private function summarizeOlder(array $older, int $olderLastId, int $olderCount, ?int $userId, int $chatId): ?string
    {
        $summaryMax = $this->config->getSummaryMaxChars();
        $tiers = $this->config->getTiers();

        // Bound cost on very long chats: only the most recent slice of the older
        // span feeds the summarizer (the cache key still uses the full count).
        $maxSource = $this->config->getMaxSourceMessages();
        $source = count($older) > $maxSource ? array_slice($older, -$maxSource) : $older;

        $cacheKey = $this->cacheKey($chatId, $olderLastId, $olderCount);
        $item = $this->cache->getItem($cacheKey);
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_string($cached) && '' !== $cached) {
                return $cached;
            }
        }

        try {
            $modelConfig = $this->modelConfigService->getSummaryModelConfig($userId);

            $messages = [
                ['role' => 'system', 'content' => $this->buildSummarizerSystemPrompt($summaryMax)],
                ['role' => 'user', 'content' => $this->buildSourceText($source, $tiers)],
            ];

            $response = $this->aiFacade->chat($messages, $userId, [
                'provider' => $modelConfig['provider'] ?? null,
                'model' => $modelConfig['model'] ?? null,
                'temperature' => 0.2,
                'max_tokens' => $this->tokenBudgetFor($summaryMax),
            ]);

            $summary = $this->clip(trim((string) ($response['content'] ?? '')), $summaryMax);

            if ('' === $summary) {
                return null;
            }

            $item->set($summary);
            $item->expiresAfter($this->config->getCacheTtl());
            $this->cache->save($item);

            $this->logger->info('ConversationSummaryService: built rolling summary', [
                'chat_id' => $chatId,
                'older_count' => $olderCount,
                'summary_chars' => mb_strlen($summary),
                'provider' => $modelConfig['provider'] ?? null,
                'model' => $modelConfig['model'] ?? null,
            ]);

            return $summary;
        } catch (\Throwable $e) {
            // Never break the chat turn because summarization failed.
            $this->logger->warning('ConversationSummaryService: summarization failed, falling back', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Look up a cached summary for a stable older-span without loading history.
     */
    private function readCachedSummary(int $chatId, int $olderLastId, int $olderCount): ?string
    {
        $item = $this->cache->getItem($this->cacheKey($chatId, $olderLastId, $olderCount));
        if ($item->isHit()) {
            $cached = $item->get();
            if (is_string($cached) && '' !== $cached) {
                return $cached;
            }
        }

        return null;
    }

    /**
     * Per-span cache key. Config knobs that change the summary shape are folded
     * into a fingerprint so a settings change invalidates stale summaries.
     */
    private function cacheKey(int $chatId, int $olderLastId, int $olderCount): string
    {
        $fingerprint = md5(implode(':', [
            $this->config->getSummaryMaxChars(),
            $this->config->getTiers(),
            $this->config->getRecentVerbatimChars(),
        ]));

        return sprintf('conv_summary.%d.%d.%d.%s', $chatId, $olderLastId, $olderCount, $fingerprint);
    }

    private function buildSummarizerSystemPrompt(int $summaryMax): string
    {
        return <<<PROMPT
            You compress the earlier part of an ongoing chat conversation into a compact "rolling summary" that a chat assistant will read as background context. The most recent turns are shown to the assistant separately and verbatim, so DO NOT restate them — summarize only what is provided below.

            Rules:
            - Write in the SAME language the conversation uses.
            - Apply GRADIENT compression: segments are ordered oldest → newest. Condense the OLDEST segment the hardest (only durable facts / the overall topic). Condense LATER segments progressively less, keeping more specifics for the newest segment.
            - Preserve, above all: the main topic, the USER'S position / goal / stance, firm decisions and conclusions, important facts and constraints, and any external or web results that were referenced.
            - Note unresolved or open questions.
            - Be factual. Never invent information that is not present in the source.
            - Output plain prose/short bullet lines. No preamble, no meta commentary.
            - Keep the whole summary under {$summaryMax} characters.
            PROMPT;
    }

    /**
     * Render the older span into recency-labelled segments with per-segment
     * compression hints (oldest → condense most, newest → condense least).
     *
     * @param list<Message> $older
     */
    private function buildSourceText(array $older, int $tiers): string
    {
        $tiers = max(1, min($tiers, count($older)));
        $perTier = (int) ceil(count($older) / $tiers);
        $chunks = array_chunk($older, max(1, $perTier));
        $segmentCount = count($chunks);

        $lines = [];
        foreach ($chunks as $index => $chunk) {
            $lines[] = sprintf('## Segment %d of %d (%s):', $index + 1, $segmentCount, $this->compressionHint($index, $segmentCount));
            foreach ($chunk as $msg) {
                $lines[] = $this->renderMessage($msg);
            }
            $lines[] = '';
        }

        return trim(implode("\n", $lines));
    }

    private function compressionHint(int $index, int $segmentCount): string
    {
        if ($segmentCount <= 1) {
            return 'condense to the essentials';
        }

        if (0 === $index) {
            return 'oldest — condense aggressively, essentials only';
        }

        if ($index === $segmentCount - 1) {
            return 'most recent of the older turns — condense lightly, keep specifics and the current position';
        }

        return 'middle — condense moderately';
    }

    private function renderMessage(Message $msg): string
    {
        $role = 'IN' === $msg->getDirection() ? 'user' : 'assistant';
        $text = (string) $msg->getText();

        $fileText = (string) $msg->getFileText();
        if ('' !== $fileText) {
            $text .= ' [attached '.((string) $msg->getFileType()).': '.$this->clip($fileText, 500).']';
        }

        return sprintf('[#%d %s]: %s', (int) $msg->getId(), $role, $this->clip($text, ConversationSummaryConstants::SOURCE_MESSAGE_CHAR_CAP));
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

    /**
     * Rough char→token budget (~3 chars/token) with headroom, so the model is
     * not cut off before it reaches the character cap we later enforce.
     */
    private function tokenBudgetFor(int $summaryMaxChars): int
    {
        return max(256, (int) ceil($summaryMaxChars / 3) + 256);
    }
}
