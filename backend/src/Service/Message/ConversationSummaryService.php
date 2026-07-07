<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
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
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param Message[] $fullHistory full chat history, chronological (oldest first)
     */
    public function buildRollingContext(array $fullHistory, ?int $userId, ?int $chatId): RollingSummaryResult
    {
        if (!$this->config->isEnabled() || null === $chatId || [] === $fullHistory) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        $fullHistory = array_values($fullHistory);

        // Split newest→oldest into a verbatim "recent" tail and an "older" head.
        $recentBudget = $this->config->getRecentVerbatimChars();
        $recentReversed = [];
        $recentChars = 0;
        foreach (array_reverse($fullHistory) as $msg) {
            $len = $this->messageLength($msg);
            if (count($recentReversed) > 0 && ($recentChars + $len) > $recentBudget) {
                break;
            }
            $recentReversed[] = $msg;
            $recentChars += $len;
        }

        /** @var list<Message> $recentMessages */
        $recentMessages = array_reverse($recentReversed);
        $olderCount = count($fullHistory) - count($recentMessages);

        // Everything already fits verbatim → behave exactly like today.
        if ($olderCount <= 0) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        /** @var list<Message> $older */
        $older = array_slice($fullHistory, 0, $olderCount);

        // Bound cost on very long chats: keep the most recent slice of the older span.
        $maxSource = $this->config->getMaxSourceMessages();
        if (count($older) > $maxSource) {
            $older = array_slice($older, -$maxSource);
        }

        $olderChars = 0;
        foreach ($older as $msg) {
            $olderChars += $this->messageLength($msg);
        }
        if ($olderChars < self::MIN_OLDER_CHARS) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        $summary = $this->summarizeOlder($older, $userId, $chatId);
        if (null === $summary || '' === trim($summary)) {
            return RollingSummaryResult::notApplied($fullHistory);
        }

        return new RollingSummaryResult(true, $summary, $recentMessages, count($older));
    }

    /**
     * Summarize the older span with gradient compression, using a per-span cache.
     *
     * @param list<Message> $older
     */
    private function summarizeOlder(array $older, ?int $userId, int $chatId): ?string
    {
        $lastOlder = $older[array_key_last($older)];
        $summaryMax = $this->config->getSummaryMaxChars();
        $tiers = $this->config->getTiers();

        $fingerprint = md5(implode(':', [$summaryMax, $tiers, $this->config->getRecentVerbatimChars()]));
        $cacheKey = sprintf('conv_summary.%d.%d.%d.%s', $chatId, (int) $lastOlder->getId(), count($older), $fingerprint);

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
                ['role' => 'user', 'content' => $this->buildSourceText($older, $tiers)],
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
                'older_count' => count($older),
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

        $fileText = $msg->getFileText();
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
