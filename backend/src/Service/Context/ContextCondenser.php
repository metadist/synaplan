<?php

declare(strict_types=1);

namespace App\Service\Context;

use App\AI\Service\AiFacade;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use Psr\Log\LoggerInterface;

/**
 * The "stacked loop": fits an arbitrarily large text into a character budget
 * without blowing the answering model's context window.
 *
 *   1. Fits verbatim?                      → return unchanged.
 *   2. Condensation disabled?              → head/tail trim to budget.
 *   3. Otherwise, up to MAX_LEVELS rounds:
 *        split into chunks the CONDENSER model can hold,
 *        condense every chunk with the user's question as the lens
 *        ("keep what could answer this, keep exact figures"),
 *        join, and re-check against the budget.
 *      The budget-per-chunk shrinks the text by roughly the ratio needed, so
 *      a 4× overflow normally resolves in one round and a 60× overflow in two.
 *   4. Still too large (or the condenser failed)? → head/tail trim.
 *
 * The condenser model is the cheap routing/analysis tier (DEFAULTMODEL
 * ANALYZE → SORT → CHAT); the expensive answering model only ever sees the
 * fitted result. Every condenser call is recorded as `CONDENSE` usage.
 */
final class ContextCondenser
{
    public const USAGE_ACTION = 'CONDENSE';

    /** Never ask the condenser for fewer characters than this per chunk. */
    private const MIN_TARGET_CHARS_PER_CHUNK = 500;

    /** Output reservation for one condenser call (tokens). */
    private const CONDENSER_OUTPUT_TOKENS = 6000;

    /** A round that shrinks less than this share is considered stalled. */
    private const MIN_PROGRESS_RATIO = 0.85;

    /** Head/tail split of a hard trim: profile + start first, totals at the end. */
    private const TRIM_HEAD_SHARE = 0.7;

    /**
     * Short function words skipped when scoring extractive passages.
     *
     * @var array<string, true>
     */
    private const EXTRACT_STOP_WORDS = [
        'the' => true, 'and' => true, 'for' => true, 'are' => true, 'was' => true, 'were' => true,
        'this' => true, 'that' => true, 'with' => true, 'from' => true, 'about' => true, 'what' => true,
        'which' => true, 'when' => true, 'where' => true, 'how' => true, 'why' => true, 'who' => true,
        'into' => true, 'over' => true, 'after' => true, 'before' => true, 'could' => true, 'should' => true,
        'would' => true, 'just' => true, 'also' => true, 'than' => true, 'then' => true, 'its' => true,
        'have' => true, 'has' => true, 'had' => true, 'not' => true, 'but' => true, 'you' => true,
        'your' => true, 'our' => true, 'their' => true, 'they' => true, 'will' => true, 'can' => true,
        'all' => true, 'any' => true, 'been' => true, 'being' => true,
        'der' => true, 'die' => true, 'das' => true, 'und' => true, 'oder' => true, 'ist' => true,
        'sind' => true, 'war' => true, 'ein' => true, 'eine' => true, 'einer' => true, 'einem' => true,
        'einen' => true, 'von' => true, 'mit' => true, 'auf' => true, 'aus' => true, 'bei' => true,
        'nach' => true, 'über' => true, 'unter' => true, 'für' => true, 'als' => true, 'dem' => true,
        'den' => true, 'des' => true, 'wie' => true, 'was' => true, 'wer' => true, 'nicht' => true,
        'auch' => true, 'nur' => true, 'noch' => true, 'sich' => true, 'dass' => true, 'diese' => true,
        'dieser' => true, 'dieses' => true,
        'que' => true, 'los' => true, 'las' => true, 'del' => true, 'una' => true, 'por' => true,
        'con' => true, 'para' => true, 'como' => true, 'más' => true, 'este' => true, 'esta' => true,
        'esto' => true, 'pero' => true, 'sus' => true, 'son' => true,
        'les' => true, 'des' => true, 'une' => true, 'dans' => true, 'pour' => true, 'qui' => true,
        'sur' => true, 'pas' => true, 'plus' => true, 'est' => true, 'avec' => true, 'par' => true,
        'sont' => true,
        'bir' => true, 'ile' => true, 'için' => true,
    ];

    public function __construct(
        private readonly AiFacade $aiFacade,
        private readonly ModelConfigService $modelConfigService,
        private readonly ModelContextWindow $modelContextWindow,
        private readonly ContextChunker $chunker,
        private readonly TokenEstimator $tokenEstimator,
        private readonly ContextFittingConfig $config,
        private readonly RateLimitService $rateLimitService,
        private readonly UserRepository $userRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fit `$text` into `$budgetChars`, using `$question` as the relevance lens.
     *
     * `$preferFastModel` picks the router (SORT) model before Text Analytics.
     * Condensing is mechanical work — keep the facts, drop the boilerplate —
     * and it sits on the critical path of a live chat turn: a frontier model
     * chosen for document analysis took 30 s to condense one news page,
     * while the router model does the same in a few seconds.
     *
     * `$preferExtractive` skips the model entirely and keeps the question-
     * relevant passages verbatim. Used on the live web-research path: three
     * news pages otherwise cost ~2.7 s each on the critical path, and the
     * answering model is better served by exact quotes than a rewrite.
     *
     * @param callable(array{level:int,chunk:int,chunks:int}):void|null $onProgress
     */
    public function fit(string $text, string $question, int $budgetChars, ?int $userId, ?callable $onProgress = null, bool $preferFastModel = false, bool $preferExtractive = false): CondensedText
    {
        $originalChars = mb_strlen($text);
        $budgetChars = max(1, $budgetChars);

        if ($originalChars <= $budgetChars) {
            return CondensedText::verbatim($text, $budgetChars);
        }

        if ($preferExtractive) {
            return $this->extracted($text, $question, $budgetChars, $originalChars);
        }

        if (!$this->config->isCondenseEnabled($userId)) {
            return $this->trimmed($text, $budgetChars, $originalChars, 0, [], 0);
        }

        $condenserModelId = $this->resolveCondenserModel($userId, $preferFastModel);
        if (null === $condenserModelId) {
            $this->logger->warning('ContextCondenser: no condenser model available, trimming instead', [
                'user_id' => $userId,
                'original_chars' => $originalChars,
            ]);

            return $this->trimmed($text, $budgetChars, $originalChars, 0, [], 0);
        }

        $chunkChars = $this->condenserChunkChars($condenserModelId, $text, $userId);
        $maxLevels = $this->config->maxLevels($userId);

        $current = $text;
        $levels = 0;
        $chunksPerLevel = [];
        $modelCalls = 0;

        while (mb_strlen($current) > $budgetChars && $levels < $maxLevels) {
            ++$levels;
            $chunks = $this->chunker->split($current, $chunkChars);
            $chunkCount = count($chunks);
            $chunksPerLevel[] = $chunkCount;

            // Aim below the budget so the joined result lands inside it even
            // when the model ignores the length hint slightly.
            $targetPerChunk = max(
                self::MIN_TARGET_CHARS_PER_CHUNK,
                (int) floor(($budgetChars * 0.85) / max(1, $chunkCount)),
            );

            $parts = [];
            foreach ($chunks as $index => $chunk) {
                if (null !== $onProgress) {
                    $onProgress(['level' => $levels, 'chunk' => $index + 1, 'chunks' => $chunkCount]);
                }

                $condensed = $this->condenseChunk($chunk, $question, $targetPerChunk, $index + 1, $chunkCount, $condenserModelId, $userId);
                ++$modelCalls;

                if (null === $condensed) {
                    // Provider failure mid-round: keep what we have, fall through to trim.
                    $this->logger->warning('ContextCondenser: condenser call failed, falling back to trim', [
                        'level' => $levels,
                        'chunk' => $index + 1,
                        'chunks' => $chunkCount,
                    ]);

                    return $this->trimmed($current, $budgetChars, $originalChars, $levels, $chunksPerLevel, $modelCalls);
                }

                $parts[] = $condensed;
            }

            $next = implode("\n\n", $parts);
            $stalled = mb_strlen($next) > mb_strlen($current) * self::MIN_PROGRESS_RATIO && mb_strlen($next) > $budgetChars;
            $current = $next;

            if ($stalled) {
                $this->logger->info('ContextCondenser: round made no progress, stopping', [
                    'level' => $levels,
                    'chars' => mb_strlen($current),
                    'budget' => $budgetChars,
                ]);
                break;
            }
        }

        if (mb_strlen($current) > $budgetChars) {
            $result = $this->trimmed($current, $budgetChars, $originalChars, $levels, $chunksPerLevel, $modelCalls, true);
        } else {
            $result = new CondensedText(
                text: $current,
                strategy: CondensedText::STRATEGY_CONDENSED,
                originalChars: $originalChars,
                budgetChars: $budgetChars,
                levels: $levels,
                chunksPerLevel: $chunksPerLevel,
                modelCalls: $modelCalls,
            );
        }

        $this->logger->info('ContextCondenser: fitted text to budget', $result->toLogContext() + ['user_id' => $userId]);

        return $result;
    }

    /**
     * Question-aware extractive fit: keep the lede and the passages that
     * overlap the question, in original order. No model call — used on the
     * live web-research path where an extra few seconds per page is the wait
     * the user feels. Falls back to head/tail trim when there is no paragraph
     * structure to rank.
     */
    public function extracted(string $text, string $question, int $budgetChars, ?int $originalChars = null): CondensedText
    {
        $originalChars ??= mb_strlen($text);
        $budgetChars = max(1, $budgetChars);

        if ($originalChars <= $budgetChars) {
            return CondensedText::verbatim($text, $budgetChars);
        }

        $blocks = $this->splitBlocks($text);
        if (count($blocks) <= 1) {
            return $this->trimmed($text, $budgetChars, $originalChars);
        }

        $terms = $this->questionTerms($question);
        $ranked = [];
        foreach ($blocks as $index => $block) {
            $ranked[] = [
                'index' => $index,
                'text' => $block,
                'score' => $this->scoreBlock($block, $terms, 0 === $index),
                'length' => mb_strlen($block),
            ];
        }
        usort(
            $ranked,
            static fn (array $a, array $b): int => $b['score'] <=> $a['score'] ?: $a['index'] <=> $b['index'],
        );

        $picked = [];
        $used = 0;
        foreach ($ranked as $candidate) {
            if ($candidate['score'] <= 0.0 && 0 !== $candidate['index']) {
                continue;
            }
            $extra = $used > 0 ? 2 : 0;
            if ($used + $extra + $candidate['length'] > $budgetChars) {
                continue;
            }
            $picked[$candidate['index']] = $candidate['text'];
            $used += $extra + $candidate['length'];
        }

        if ([] === $picked) {
            return $this->trimmed($text, $budgetChars, $originalChars);
        }

        ksort($picked);
        $extracted = implode("\n\n", $picked);
        if (mb_strlen($extracted) > $budgetChars) {
            return $this->trimmed($extracted, $budgetChars, $originalChars);
        }

        $result = new CondensedText(
            text: $extracted,
            strategy: CondensedText::STRATEGY_EXTRACTED,
            originalChars: $originalChars,
            budgetChars: $budgetChars,
        );

        $this->logger->info('ContextCondenser: extracted question-relevant passages', $result->toLogContext());

        return $result;
    }

    /**
     * Head/tail cut with an explicit omission marker. Used when condensing is
     * off, unavailable, or did not reach the budget.
     *
     * @param list<int> $chunksPerLevel
     */
    public function trimmed(string $text, int $budgetChars, ?int $originalChars = null, int $levels = 0, array $chunksPerLevel = [], int $modelCalls = 0, bool $afterCondense = false): CondensedText
    {
        $originalChars ??= mb_strlen($text);
        $length = mb_strlen($text);

        if ($length <= $budgetChars) {
            return new CondensedText($text, $afterCondense ? CondensedText::STRATEGY_CONDENSED : CondensedText::STRATEGY_VERBATIM, $originalChars, $budgetChars, $levels, $chunksPerLevel, false, $modelCalls);
        }

        $marker = sprintf("\n\n[… %s characters omitted …]\n\n", number_format($length - $budgetChars));
        $available = max(0, $budgetChars - mb_strlen($marker));
        $headChars = (int) floor($available * self::TRIM_HEAD_SHARE);
        $tailChars = $available - $headChars;

        $head = mb_substr($text, 0, $headChars);
        $tail = $tailChars > 0 ? mb_substr($text, $length - $tailChars) : '';

        // Do not cut mid-line when a nearby line break exists.
        $lastBreak = mb_strrpos($head, "\n");
        if (false !== $lastBreak && $lastBreak > $headChars * 0.8) {
            $head = mb_substr($head, 0, $lastBreak);
        }
        $firstBreak = mb_strpos($tail, "\n");
        if (false !== $firstBreak && $firstBreak < $tailChars * 0.2) {
            $tail = mb_substr($tail, $firstBreak + 1);
        }

        return new CondensedText(
            text: rtrim($head).$marker.ltrim($tail),
            strategy: CondensedText::STRATEGY_TRIMMED,
            originalChars: $originalChars,
            budgetChars: $budgetChars,
            levels: $levels,
            chunksPerLevel: $chunksPerLevel,
            trimmedAfterCondense: $afterCondense,
            modelCalls: $modelCalls,
        );
    }

    /**
     * @return list<string>
     */
    private function splitBlocks(string $text): array
    {
        $normalized = preg_replace("/\r\n?/", "\n", $text) ?? $text;
        $parts = preg_split("/\n{2,}/", $normalized) ?: [];
        $blocks = [];
        foreach ($parts as $part) {
            $trimmed = trim($part);
            if ('' !== $trimmed) {
                $blocks[] = $trimmed;
            }
        }

        if (count($blocks) <= 1 && mb_strlen($normalized) > 800) {
            $sentences = preg_split('/(?<=[.!?])\s+/u', trim($normalized)) ?: [];
            $blocks = [];
            foreach ($sentences as $sentence) {
                $trimmed = trim($sentence);
                if ('' !== $trimmed) {
                    $blocks[] = $trimmed;
                }
            }
        }

        return $blocks;
    }

    /**
     * @return list<string>
     */
    private function questionTerms(string $question): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($question)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (mb_strlen($word) < 3 || isset(self::EXTRACT_STOP_WORDS[$word])) {
                continue;
            }
            $terms[] = $word;
        }

        return array_values(array_unique($terms));
    }

    /**
     * @param list<string> $terms
     */
    private function scoreBlock(string $block, array $terms, bool $isLede): float
    {
        $lower = mb_strtolower($block);
        $score = $isLede ? 4.0 : 0.0;
        foreach ($terms as $term) {
            if (str_contains($lower, $term)) {
                $score += 2.0;
            }
        }
        if (preg_match('/\d/', $block)) {
            $score += 1.0;
        }
        if (mb_strlen($block) < 40) {
            $score -= 1.5;
        }

        return $score;
    }

    private function resolveCondenserModel(?int $userId, bool $preferFastModel): ?int
    {
        $order = $preferFastModel ? ['SORT', 'ANALYZE', 'CHAT'] : ['ANALYZE', 'SORT', 'CHAT'];
        foreach ($order as $capability) {
            try {
                $modelId = $this->modelConfigService->getDefaultModel($capability, $userId);
            } catch (\Throwable) {
                $modelId = null;
            }
            if (null !== $modelId && $modelId > 0) {
                return $modelId;
            }
        }

        return null;
    }

    /**
     * Chunk size (chars) one condenser call may receive: bounded by the
     * operator setting AND by the condenser model's own window.
     */
    private function condenserChunkChars(int $condenserModelId, string $sample, ?int $userId): int
    {
        $spec = $this->modelContextWindow->forModel($condenserModelId);
        $windowTokens = (int) floor($spec->inputTokens(self::CONDENSER_OUTPUT_TOKENS) * 0.7);
        $tokens = min($this->config->condenserChunkTokens($userId), max(2000, $windowTokens));

        return $this->tokenEstimator->charsForTokens($tokens, $sample);
    }

    private function condenseChunk(string $chunk, string $question, int $targetChars, int $index, int $total, int $modelId, ?int $userId): ?string
    {
        $targetWords = max(80, (int) floor($targetChars / 6));
        $question = trim($question);

        $system = implode("\n", [
            'You condense one part of a large document so that a later AI model can answer a user\'s question with it.',
            'Rules:',
            '- Keep every fact, number, name, date, identifier, and table value that could matter for the question. Quote figures exactly as written.',
            '- Keep cell references and sheet names (e.g. Sheet1!B12) when they appear, so the answer can point to the source.',
            '- Keep structure: use short headings, bullet points, or compact Markdown tables. Prefer aggregates (totals, ranges, counts per category) over listing every row when rows repeat.',
            '- Drop boilerplate, navigation text, repeated headers, and anything irrelevant to the question.',
            '- Do NOT answer the question, do NOT add commentary or opinions, do NOT invent values.',
            '- Write in the language of the document.',
            sprintf('- Hard limit: about %d words.', $targetWords),
        ]);

        $lens = '' !== $question
            ? "QUESTION THE FINAL ANSWER MUST ADDRESS:\n".$question
            : 'QUESTION THE FINAL ANSWER MUST ADDRESS: (none given — preserve the most important content: what the document is, its structure, key figures, and conclusions)';

        $user = sprintf("%s\n\nDOCUMENT PART %d OF %d:\n%s", $lens, $index, $total, $chunk);

        try {
            $response = $this->aiFacade->chat(
                [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                $userId,
                [
                    'provider' => $this->modelConfigService->getProviderForModel($modelId),
                    'model' => $this->modelConfigService->getModelName($modelId),
                    'temperature' => 0.1,
                    'max_tokens' => self::CONDENSER_OUTPUT_TOKENS,
                ],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('ContextCondenser: condenser model call failed', [
                'model_id' => $modelId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $this->recordUsage($userId, $modelId, $response);

        $content = trim((string) ($response['content'] ?? ''));

        return '' === $content ? null : $content;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function recordUsage(?int $userId, int $modelId, array $response): void
    {
        if (null === $userId || $userId <= 0) {
            return;
        }

        try {
            $user = $this->userRepository->find($userId);
            if (null === $user) {
                return;
            }

            $this->rateLimitService->recordUsage($user, self::USAGE_ACTION, [
                'usage' => $response['usage'] ?? [],
                'model_id' => $modelId,
                'provider' => $response['provider'] ?? '',
                'model' => $response['model'] ?? '',
                'input_text' => '',
                'response_text' => $response['content'] ?? '',
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('ContextCondenser: failed to record usage', [
                'error' => $e->getMessage(),
                'user_id' => $userId,
            ]);
        }
    }
}
