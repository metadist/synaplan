<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
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
class RateLimitService
{
    private const CACHE_TTL = 300; // 5 minutes cache
    private array $limitsCache = [];

    public function __construct(
        private ConfigRepository $configRepository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private BillingService $billingService,
    ) {
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
     * Record usage of an action.
     *
     * If 'tokens' is 0 and 'response_text' or 'response_bytes' is provided,
     * tokens are auto-estimated using the bytes/1.3 heuristic.
     */
    public function recordUsage(User $user, string $action, array $metadata = []): void
    {
        $tokens = $metadata['tokens'] ?? 0;

        // Auto-estimate tokens if provider didn't return real token counts
        if (0 === $tokens || empty($tokens)) {
            $totalBytes = 0;

            // Count text response bytes
            if (!empty($metadata['response_text'])) {
                $totalBytes += strlen($metadata['response_text']);
            }

            // Count input prompt bytes (if provided)
            if (!empty($metadata['input_text'])) {
                $totalBytes += strlen($metadata['input_text']);
            }

            // Add explicit byte count for media (images, audio, video)
            if (!empty($metadata['response_bytes'])) {
                $totalBytes += (int) $metadata['response_bytes'];
            }

            if ($totalBytes > 0) {
                $tokens = self::estimateTokens($totalBytes);
            }
        }

        // Remove internal-only fields from stored metadata (keep it clean)
        $storedMetadata = $metadata;
        unset($storedMetadata['response_text'], $storedMetadata['input_text'], $storedMetadata['response_bytes']);

        $this->em->getConnection()->executeStatement(
            'INSERT INTO BUSELOG (BUSERID, BUNIXTIMES, BACTION, BPROVIDER, BMODEL, BTOKENS, BCOST, BLATENCY, BSTATUS, BERROR, BMETADATA) 
             VALUES (:user_id, :timestamp, :action, :provider, :model, :tokens, :cost, :latency, :status, :error, :metadata)',
            [
                'user_id' => $user->getId(),
                'timestamp' => time(),
                'action' => $action,
                'provider' => $metadata['provider'] ?? '',
                'model' => $metadata['model'] ?? '',
                'tokens' => $tokens,
                'cost' => $metadata['cost'] ?? 0,
                'latency' => $metadata['latency'] ?? 0,
                'status' => 'success',
                'error' => '',
                'metadata' => json_encode($storedMetadata),
            ]
        );

        $this->logger->info('Rate limit usage recorded', [
            'user_id' => $user->getId(),
            'action' => $action,
            'tokens' => $tokens,
            'estimated' => (0 === ($metadata['tokens'] ?? 0)),
        ]);
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
     * Get all limits for a user (for display).
     */
    public function getUserLimits(User $user): array
    {
        $level = $user->getRateLimitLevel();
        $actions = ['MESSAGES', 'IMAGES', 'VIDEOS', 'AUDIOS', 'FILE_ANALYSIS'];

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
