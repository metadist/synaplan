<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\SubscriptionRepository;
use App\Repository\TopupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Rate Limiting Service.
 *
 * Uses BCONFIG for limits and BUSELOG for tracking usage
 *
 * Supports:
 * - NEW: Lifetime totals (never reset)
 * - PRO/TEAM/BUSINESS: Hourly + Monthly limits
 */
final class RateLimitService
{
    private const CACHE_TTL = 300; // 5 minutes cache

    /**
     * Default usage markup applied to provider cost when evaluating a user's
     * cost budget. 10 → users are billed 110% of the raw provider cost. Operators
     * can override via BCONFIG group "BILLING", setting "MARKUP_PERCENT".
     */
    private const DEFAULT_MARKUP_PERCENT = 10.0;

    public const MARKUP_CONFIG_GROUP = 'BILLING';
    public const MARKUP_CONFIG_SETTING = 'MARKUP_PERCENT';

    private array $limitsCache = [];

    public function __construct(
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private BillingService $billingService,
        private CostCalculationService $costCalculationService,
        private SubscriptionRepository $subscriptionRepository,
        private TopupRepository $topupRepository,
    ) {
    }

    /**
     * Resolve the configured usage-markup percent (operator-tunable via BCONFIG
     * BILLING/MARKUP_PERCENT, default 10). Clamped to a sane 0–1000% range.
     */
    public function getMarkupPercent(): float
    {
        $raw = $this->configRepository->getValue(0, self::MARKUP_CONFIG_GROUP, self::MARKUP_CONFIG_SETTING);
        if (null === $raw || '' === trim($raw) || !is_numeric($raw)) {
            return self::DEFAULT_MARKUP_PERCENT;
        }

        return max(0.0, min(1000.0, (float) $raw));
    }

    /**
     * Check if user can perform action.
     *
     * @param string $action MESSAGES|IMAGES|VIDEOS|AUDIOS|FILE_ANALYSIS
     *
     * @return array ['allowed' => bool, 'limit' => int, 'used' => int, 'remaining' => int, 'resets_at' => ?int]
     */
    public function checkLimit(User $user, string $action): array
    {
        // If billing is disabled (Open Source Mode), all users have unlimited usage
        if (!$this->billingService->isEnabled()) {
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'used' => 0,
                'remaining' => PHP_INT_MAX,
                'reset_at' => null,
                'limit_type' => 'unlimited',
            ];
        }

        $level = $user->getRateLimitLevel();

        $this->logger->debug('Rate limit check', [
            'user_id' => $user->getId(),
            'level' => $level,
            'action' => $action,
        ]);

        // Admins have unlimited usage
        if ('ADMIN' === $level) {
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'used' => 0,
                'remaining' => PHP_INT_MAX,
                'reset_at' => null,
                'limit_type' => 'unlimited',
            ];
        }

        // Get limits for user level
        $limits = $this->getLimitsForLevel($level, $action);

        if (empty($limits)) {
            // No limits configured - allow
            return [
                'allowed' => true,
                'limit' => PHP_INT_MAX,
                'used' => 0,
                'remaining' => PHP_INT_MAX,
                'reset_at' => null,
                'limit_type' => 'unlimited',
            ];
        }

        // NEW & ANONYMOUS users: lifetime limits (never reset)
        if (in_array($level, ['NEW', 'ANONYMOUS'], true)) {
            return $this->checkLifetimeLimit($user, $action, $limits);
        }

        // PRO/TEAM/BUSINESS: hourly + monthly limits
        return $this->checkPeriodLimit($user, $action, $limits);
    }

    /**
     * Estimate token count from byte length.
     *
     * Uses a simple heuristic: ~1.3 bytes per token on average for mixed-language text.
     * For media content (images/audio/video), the byte count is used as-is.
     * This provides a rough but useful estimate when providers don't return exact token counts.
     *
     * @param int $bytes Total bytes of content (text + media)
     *
     * @return int Estimated token count (rounded up)
     */
    public static function estimateTokens(int $bytes): int
    {
        if ($bytes <= 0) {
            return 0;
        }

        return (int) ceil($bytes / 1.3);
    }

    /**
     * Record FILE_ANALYSIS usage exactly once per (user, file).
     *
     * The chat upload, the RAG `processSingleUpload` path and the async
     * `processFile` retry path all converge on the same business event —
     * "this user has had this file analysed" — so they must not produce
     * more than one BUSELOG row between them. Issue #887: a vectorize
     * retry after Qdrant came back online used to write a second row, and
     * the chat-upload-without-send used to bill for an analysis that
     * never actually happened.
     *
     * Idempotency key = (BUSERID, BACTION='FILE_ANALYSIS', BMETADATA->file_id).
     * The check + insert run as a single SQL statement
     * (`INSERT ... SELECT ... WHERE NOT EXISTS`) so two concurrent workers
     * hitting the same file id can never both observe "no row" and both
     * insert — Copilot review on PR #930 flagged the prior
     * SELECT-then-INSERT pattern as race-prone, this closes that window
     * without requiring a schema change.
     *
     * FILE_ANALYSIS rows carry no provider / model / token / cost data
     * (the analysis is a billable *event*, not a billable AI call), so
     * the inlined INSERT below intentionally hard-codes those columns to
     * zero / empty rather than going through `recordUsage()` which would
     * spend a price-snapshot lookup on values that are guaranteed zero.
     *
     * Return semantics:
     *   - `true`  → a row was written for this call.
     *   - `false` → a row for the same (user, file) already existed and
     *               the call was a no-op (deduplicated).
     *
     * Callers that pass `fileId <= 0` get the legacy non-deduped
     * `recordUsage()` path and always receive `true` — see the comment
     * inside the early return below for the rationale.
     */
    public function recordFileAnalysisOnce(User $user, int $fileId, array $metadata = []): bool
    {
        if ($fileId <= 0) {
            // Defensive: callers that don't have a file id yet (e.g. a
            // pre-upload billing check or an error path before the File
            // entity is persisted) must keep getting their row. There is
            // no idempotency key, so we cannot dedup; we always write
            // and always return `true`.
            $this->recordUsage($user, 'FILE_ANALYSIS', $metadata);

            return true;
        }

        // Always set file_id explicitly so the WHERE NOT EXISTS clause
        // and the BMETADATA we INSERT line up regardless of whether the
        // caller remembered to include it in `$metadata`.
        $metadata['file_id'] = $fileId;

        // Trim transient fields the same way `recordUsage()` does so
        // BMETADATA stays small and consistent across both code paths.
        $storedMetadata = $metadata;
        unset(
            $storedMetadata['response_text'],
            $storedMetadata['input_text'],
            $storedMetadata['input_bytes'],
            $storedMetadata['response_bytes'],
            $storedMetadata['usage'],
            $storedMetadata['model_id'],
            $storedMetadata['media_usage'],
        );

        $affectedRows = $this->em->getConnection()->executeStatement(
            <<<'SQL'
            INSERT INTO BUSELOG (
                BUSERID, BUNIXTIMES, BACTION,
                BPROVIDER, BMODEL, BTOKENS,
                BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS,
                BESTIMATED, BMODEL_ID, BPRICE_SNAPSHOT,
                BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA
            )
            SELECT
                :user_id, :timestamp, 'FILE_ANALYSIS',
                '', '', 0,
                0, 0, 0, 0,
                0, NULL, NULL,
                0, 0, 'success', '', :metadata
            FROM DUAL
            WHERE NOT EXISTS (
                SELECT 1 FROM BUSELOG
                 WHERE BUSERID = :user_id
                   AND BACTION = 'FILE_ANALYSIS'
                   AND JSON_EXTRACT(BMETADATA, '$.file_id') = :file_id
            )
            SQL,
            [
                'user_id' => $user->getId(),
                'timestamp' => time(),
                'metadata' => json_encode($storedMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'file_id' => $fileId,
            ]
        );

        if ($affectedRows < 1) {
            $this->logger->debug('RateLimitService: FILE_ANALYSIS already recorded for file, skipping', [
                'user_id' => $user->getId(),
                'file_id' => $fileId,
            ]);

            return false;
        }

        return true;
    }

    /**
     * Record usage of an action.
     *
     * Accepts granular token data from provider usage arrays.
     * Falls back to byte-based heuristic estimation if no token data available.
     */
    public function recordUsage(User $user, string $action, array $metadata = []): void
    {
        $usage = $metadata['usage'] ?? [];
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;
        $cachedTokens = $usage['cached_tokens'] ?? 0;
        $cacheCreationTokens = $usage['cache_creation_tokens'] ?? 0;
        $totalTokens = $usage['total_tokens'] ?? ($promptTokens + $completionTokens);

        // Legacy support: if 'tokens' was passed directly (old API)
        $legacyTokens = $metadata['tokens'] ?? 0;
        $estimated = false;

        if (0 === $totalTokens && $legacyTokens > 0) {
            $totalTokens = $legacyTokens;
            $estimated = true;
        }

        // Resolve input byte count: prefer precomputed input_bytes over strlen(input_text)
        $inputBytes = !empty($metadata['input_bytes']) ? (int) $metadata['input_bytes'] : 0;
        if (0 === $inputBytes && !empty($metadata['input_text'])) {
            $inputBytes = strlen($metadata['input_text']);
        }

        // Auto-estimate if no token data at all
        if (0 === $totalTokens) {
            $totalBytes = $inputBytes;

            if (!empty($metadata['response_text'])) {
                $totalBytes += strlen($metadata['response_text']);
            }
            if (!empty($metadata['response_bytes'])) {
                $totalBytes += (int) $metadata['response_bytes'];
            }

            if ($totalBytes > 0) {
                $totalTokens = self::estimateTokens($totalBytes);
                $estimated = true;
            }
        }

        if ($totalTokens > 0 && 0 === $promptTokens && 0 === $completionTokens) {
            $outputBytes = (!empty($metadata['response_text']) ? strlen($metadata['response_text']) : 0)
                         + (!empty($metadata['response_bytes']) ? (int) $metadata['response_bytes'] : 0);

            if ($inputBytes > 0 && $outputBytes > 0) {
                $ratio = $inputBytes / ($inputBytes + $outputBytes);
                $promptTokens = (int) ceil($totalTokens * $ratio);
                $completionTokens = $totalTokens - $promptTokens;
                $estimated = true;
            } else {
                $promptTokens = $totalTokens;
            }
        }

        $modelId = $metadata['model_id'] ?? null;
        $mediaUsage = $metadata['media_usage'] ?? [];
        $pricingMode = $this->costCalculationService->getPricingMode($modelId);

        // Capture a single timestamp so the price-history lookup, the stored
        // price snapshot and the BUNIXTIMES column on BUSELOG all line up.
        $timestamp = time();

        if ('per_token' !== $pricingMode && !empty($mediaUsage)) {
            $inputQty = match ($pricingMode) {
                'per_character' => (float) ($mediaUsage['characters'] ?? 0),
                'per_second' => (float) ($mediaUsage['duration_seconds'] ?? 0),
                default => 0.0,
            };
            $outputQty = match ($pricingMode) {
                'per_image' => (float) ($mediaUsage['images'] ?? 0),
                'per_second' => (float) ($mediaUsage['duration_seconds'] ?? 0),
                default => 0.0,
            };

            $resolution = isset($mediaUsage['resolution']) && is_string($mediaUsage['resolution'])
                ? $mediaUsage['resolution']
                : null;

            $costResult = $this->costCalculationService->calculateMediaCost(
                $modelId,
                $inputQty,
                $outputQty,
                $timestamp,
                $resolution,
            );
        } else {
            $costResult = $this->costCalculationService->calculateCost(
                $promptTokens,
                $completionTokens,
                $cachedTokens,
                $cacheCreationTokens,
                $modelId,
                $timestamp,
            );
        }

        $storedMetadata = $metadata;
        unset(
            $storedMetadata['response_text'],
            $storedMetadata['input_text'],
            $storedMetadata['input_bytes'],
            $storedMetadata['response_bytes'],
            $storedMetadata['usage'],
            $storedMetadata['model_id'],
            $storedMetadata['media_usage'],
        );

        $this->em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS, 
             BPROMPT_TOKENS, BCOMPLETION_TOKENS, BCACHED_TOKENS, BCACHE_CREATION_TOKENS,
             BESTIMATED, BMODEL_ID, BPRICE_SNAPSHOT,
             BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA) 
             VALUES (:user_id, :timestamp, :action, :provider, :model, :tokens,
             :prompt_tokens, :completion_tokens, :cached_tokens, :cache_creation_tokens,
             :estimated, :model_id, :price_snapshot,
             :cost, :latency, :status, :error, :metadata)',
            [
                'user_id' => $user->getId(),
                'timestamp' => $timestamp,
                'action' => $action,
                'provider' => $metadata['provider'] ?? '',
                'model' => $metadata['model'] ?? '',
                'tokens' => $totalTokens,
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'cached_tokens' => $cachedTokens,
                'cache_creation_tokens' => $cacheCreationTokens,
                'estimated' => $estimated ? 1 : 0,
                'model_id' => $modelId,
                'price_snapshot' => !empty($costResult->priceSnapshot) ? json_encode($costResult->priceSnapshot) : null,
                'cost' => $costResult->totalCost,
                'latency' => $metadata['latency'] ?? 0,
                'status' => 'success',
                'error' => '',
                'metadata' => json_encode($storedMetadata),
            ]
        );

        $this->logger->info('Rate limit usage recorded', [
            'user_id' => $user->getId(),
            'action' => $action,
            'tokens' => $totalTokens,
            'cost' => $costResult->totalCost,
            'estimated' => $estimated,
        ]);
    }

    /**
     * Check if user's monthly cost budget allows another request.
     *
     * Budget is read from BSUBSCRIPTIONS regardless of Stripe being configured,
     * since token budgets are an application concern independent of payment processing.
     *
     * `used_cost` is the charged amount (raw provider cost + markup); `raw_cost`
     * is the underlying provider cost. The effective budget is the tier's monthly
     * budget plus any one-time top-ups bought within the current period.
     *
     * @return array{allowed: bool, used_cost: string, raw_cost: string, markup_percent: float, base_budget: string, topups: string, budget: string, remaining: string, percent: float, period_start: int, period_end: int}
     */
    public function checkCostBudget(User $user): array
    {
        $markupPercent = $this->getMarkupPercent();
        $markupMultiplier = 1.0 + ($markupPercent / 100.0);

        $subscription = $this->subscriptionRepository->findOneBy(['level' => $user->getRateLimitLevel()]);
        $baseBudget = $subscription ? (float) $subscription->getCostBudgetMonthly() : 0.0;

        [$periodStart, $periodEnd] = $this->getBillingPeriod($user);

        // One-time top-ups bought within this period raise the budget.
        $topups = $this->topupRepository->sumForUserInPeriod((int) $user->getId(), $periodStart, $periodEnd);
        $budget = $baseBudget + $topups;

        // Raw provider cost incurred this period (true cost, as stored in BUSELOG).
        $rawCost = (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(BCOST), 0) FROM BUSELOG WHERE BUSERID = :user_id AND BUNIXTIMES >= :period_start AND BUNIXTIMES <= :period_end',
            ['user_id' => $user->getId(), 'period_start' => $periodStart, 'period_end' => $periodEnd]
        );

        // What the user is actually billed: provider cost + markup.
        $chargedCost = $rawCost * $markupMultiplier;

        $base = [
            'used_cost' => number_format($chargedCost, 2, '.', ''),
            'raw_cost' => number_format($rawCost, 2, '.', ''),
            'markup_percent' => round($markupPercent, 2),
            'base_budget' => number_format($baseBudget, 2, '.', ''),
            'topups' => number_format($topups, 2, '.', ''),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
        ];

        // No budget configured for this tier (and no top-ups) → unlimited.
        if ($budget <= 0) {
            return [
                ...$base,
                'allowed' => true,
                'budget' => '0.00',
                'remaining' => '0.00',
                'percent' => 0.0,
            ];
        }

        $remaining = max(0.0, $budget - $chargedCost);
        $percent = min(100.0, ($chargedCost / $budget) * 100);

        return [
            ...$base,
            'allowed' => $chargedCost < $budget,
            'budget' => number_format($budget, 2, '.', ''),
            'remaining' => number_format($remaining, 2, '.', ''),
            'percent' => round($percent, 1),
        ];
    }

    /**
     * Calculate the current billing period based on the user's subscription start date.
     *
     * If the user has a subscription_start timestamp, the billing cycle anchors
     * to that day-of-month. Otherwise falls back to calendar month.
     *
     * @return array{0: int, 1: int} [period_start, period_end] as unix timestamps
     */
    public function getBillingPeriod(User $user): array
    {
        $subData = $user->getSubscriptionData();
        $subscriptionStart = $subData['subscription_start'] ?? null;

        if (!$subscriptionStart || !is_numeric($subscriptionStart)) {
            return [
                (int) strtotime('first day of this month midnight'),
                (int) strtotime('last day of this month 23:59:59'),
            ];
        }

        $anchorDay = (int) date('j', (int) $subscriptionStart);
        $now = time();
        $currentDay = (int) date('j', $now);
        $currentMonth = (int) date('n', $now);
        $currentYear = (int) date('Y', $now);

        $daysInCurrentMonth = (int) date('t', $now);
        $effectiveAnchor = min($anchorDay, $daysInCurrentMonth);

        if ($currentDay >= $effectiveAnchor) {
            $periodStart = mktime(0, 0, 0, $currentMonth, $effectiveAnchor, $currentYear);
            $nextMonth = $currentMonth + 1;
            $nextYear = $currentYear;
            if ($nextMonth > 12) {
                $nextMonth = 1;
                ++$nextYear;
            }
            $daysInNextMonth = (int) date('t', mktime(0, 0, 0, $nextMonth, 1, $nextYear));
            $periodEnd = mktime(23, 59, 59, $nextMonth, min($anchorDay, $daysInNextMonth) - 1, $nextYear);
        } else {
            $prevMonth = $currentMonth - 1;
            $prevYear = $currentYear;
            if ($prevMonth < 1) {
                $prevMonth = 12;
                --$prevYear;
            }
            $daysInPrevMonth = (int) date('t', mktime(0, 0, 0, $prevMonth, 1, $prevYear));
            $periodStart = mktime(0, 0, 0, $prevMonth, min($anchorDay, $daysInPrevMonth), $prevYear);
            $periodEnd = mktime(23, 59, 59, $currentMonth, $effectiveAnchor - 1, $currentYear);
        }

        return [(int) $periodStart, (int) $periodEnd];
    }

    /**
     * Get limits for specific level from BCONFIG.
     */
    private function getLimitsForLevel(string $level, string $action): array
    {
        $cacheKey = "{$level}_{$action}";

        if (isset($this->limitsCache[$cacheKey])) {
            return $this->limitsCache[$cacheKey];
        }

        $group = "RATELIMITS_{$level}";
        $configs = $this->configRepository->findBy([
            'ownerId' => 0,
            'group' => $group,
        ]);

        $limits = [];
        foreach ($configs as $config) {
            $setting = $config->getSetting();
            if (str_starts_with($setting, $action.'_')) {
                $timeframe = str_replace($action.'_', '', $setting);
                $limits[$timeframe] = (int) $config->getValue();
            }
        }

        $this->limitsCache[$cacheKey] = $limits;

        return $limits;
    }

    /**
     * Check lifetime limit (for NEW users).
     */
    private function checkLifetimeLimit(User $user, string $action, array $limits): array
    {
        $limit = $limits['TOTAL'] ?? PHP_INT_MAX;

        // Count total usage from BUSELOG
        $used = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM BUSELOG WHERE BUSERID = :user_id AND BACTION = :action',
            ['user_id' => $user->getId(), 'action' => $action]
        );

        $remaining = max(0, $limit - $used);
        $allowed = $used < $limit;

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'reset_at' => null, // Lifetime - never resets
            'limit_type' => 'lifetime',
        ];
    }

    /**
     * Check period limit (hourly/monthly for PRO/TEAM/BUSINESS).
     */
    private function checkPeriodLimit(User $user, string $action, array $limits): array
    {
        // Check hourly first (stricter)
        if (isset($limits['HOURLY'])) {
            $hourlyCheck = $this->checkTimeframeLimit($user, $action, $limits['HOURLY'], 3600);
            if (!$hourlyCheck['allowed']) {
                return $hourlyCheck;
            }
        }

        // Then check monthly
        if (isset($limits['MONTHLY'])) {
            $monthlyCheck = $this->checkTimeframeLimit($user, $action, $limits['MONTHLY'], 2592000); // 30 days
            if (!$monthlyCheck['allowed']) {
                if (isset($hourlyCheck)) {
                    $monthlyCheck['hourly'] = $hourlyCheck;
                }

                return $monthlyCheck;
            }

            if (isset($hourlyCheck)) {
                $monthlyCheck['hourly'] = $hourlyCheck;
            }

            return $monthlyCheck;
        }

        if (isset($hourlyCheck)) {
            return $hourlyCheck;
        }

        // No limits configured
        return [
            'allowed' => true,
            'limit' => PHP_INT_MAX,
            'used' => 0,
            'remaining' => PHP_INT_MAX,
            'reset_at' => null,
            'limit_type' => 'unlimited',
        ];
    }

    /**
     * Check usage within timeframe.
     */
    private function checkTimeframeLimit(User $user, string $action, int $limit, int $seconds): array
    {
        $since = time() - $seconds;

        $used = (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM BUSELOG 
             WHERE BUSERID = :user_id AND BACTION = :action AND BUNIXTIMES >= :since',
            [
                'user_id' => $user->getId(),
                'action' => $action,
                'since' => $since,
            ]
        );

        $remaining = max(0, $limit - $used);
        $allowed = $used < $limit;
        $resetsAt = time() + $seconds;

        return [
            'allowed' => $allowed,
            'limit' => $limit,
            'used' => $used,
            'remaining' => $remaining,
            'reset_at' => $resetsAt,
            'limit_type' => 3600 === $seconds ? 'hourly' : 'monthly',
        ];
    }

    /**
     * Get max output tokens allowed for a user based on their subscription level.
     *
     * Returns null when billing is disabled (Open Source Mode), for admins, or
     * when the level has no MAX_OUTPUT_TOKENS configured — meaning the model's
     * own max_tokens is used without restriction. By design only ANONYMOUS keeps
     * a hard cap; authenticated tiers (NEW/PRO/TEAM/BUSINESS) return null here
     * and are bounded by the cost-budget gate and message-count limits instead.
     */
    public function getMaxOutputTokens(User $user): ?int
    {
        if (!$this->billingService->isEnabled()) {
            return null;
        }

        $level = $user->getRateLimitLevel();

        if ('ADMIN' === $level) {
            return null;
        }

        $raw = $this->configRepository->getValue(0, "RATELIMITS_{$level}", 'MAX_OUTPUT_TOKENS');

        if (null === $raw) {
            return null;
        }

        $value = (int) $raw;

        return $value > 0 ? $value : null;
    }

    /**
     * Get all limits for a user (for display).
     */
    public function getUserLimits(User $user): array
    {
        $level = $user->getRateLimitLevel();
        $actions = ['MESSAGES', 'IMAGES', 'VIDEOS', 'AUDIOS', 'FILE_ANALYSIS', 'EMBEDDINGS'];

        $result = [
            'level' => $level,
            'limits' => [],
        ];

        foreach ($actions as $action) {
            $result['limits'][$action] = $this->checkLimit($user, $action);
        }

        return $result;
    }
}
