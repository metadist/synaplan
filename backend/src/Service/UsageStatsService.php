<?php

namespace App\Service;

use App\Entity\User;
use App\Repository\ConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Usage Statistics Service.
 *
 * Provides detailed usage statistics for users across all channels
 */
final readonly class UsageStatsService
{
    private const ACTION_TYPES = [
        'MESSAGES',
        'IMAGES',
        'VIDEOS',
        'AUDIOS',
        'FILE_ANALYSIS',
    ];

    public function __construct(
        private EntityManagerInterface $em,
        private ConfigRepository $configRepository,
        private RateLimitService $rateLimitService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Get comprehensive usage statistics for a user.
     *
     * @return array [
     *               'user_level' => string,
     *               'subscription' => array,
     *               'usage' => array,
     *               'limits' => array,
     *               'breakdown' => array (by source, action, time)
     *               ]
     */
    public function getUserStats(User $user): array
    {
        $level = $user->getRateLimitLevel();
        $userId = $user->getId();

        // Get usage per action type
        $usage = [];
        $limits = [];
        $remaining = [];

        foreach (self::ACTION_TYPES as $action) {
            try {
                $limitCheck = $this->rateLimitService->checkLimit($user, $action);

                $usage[$action] = [
                    'used' => $limitCheck['used'],
                    'limit' => $limitCheck['limit'],
                    'remaining' => $limitCheck['remaining'],
                    'allowed' => $limitCheck['allowed'],
                    'resets_at' => $limitCheck['resets_at'] ?? null,
                    'type' => $limitCheck['type'] ?? 'unlimited',
                ];

                $limits[$action] = $limitCheck['limit'];
                $remaining[$action] = $limitCheck['remaining'];
            } catch (\Exception $e) {
                // Fallback if rate limit check fails (e.g., config not in DB yet)
                $this->logger->warning('Failed to check rate limit for action', [
                    'action' => $action,
                    'user_id' => $userId,
                    'error' => $e->getMessage(),
                ]);

                $usage[$action] = [
                    'used' => 0,
                    'limit' => 0,
                    'remaining' => 0,
                    'allowed' => true,
                    'resets_at' => null,
                    'type' => 'unlimited',
                ];

                $limits[$action] = 0;
                $remaining[$action] = 0;
            }
        }

        // Get usage breakdown by source (WhatsApp, Email, Web)
        $sourceBreakdown = $this->getUsageBySource($userId);

        // Get usage breakdown by time period
        $timeBreakdown = $this->getUsageByTimePeriod($userId);

        // Get recent usage (last 10 actions)
        $recentUsage = $this->getRecentUsage($userId, 10);

        // Cost budget
        $costBudget = $this->rateLimitService->checkCostBudget($user);

        // Cost summary (today / this week / this month)
        $costSummary = $this->getCostSummary($userId);

        return [
            'user_level' => $level,
            'phone_verified' => $user->hasVerifiedPhone(),
            'subscription' => $this->getSubscriptionInfo($user),
            'usage' => $usage,
            'limits' => $limits,
            'remaining' => $remaining,
            'breakdown' => [
                'by_source' => $sourceBreakdown,
                'by_time' => $timeBreakdown,
            ],
            'recent_usage' => $recentUsage,
            'total_requests' => array_sum(array_column($usage, 'used')),
            'cost_budget' => [
                'used' => (float) $costBudget['used_cost'],
                'budget' => (float) $costBudget['budget'],
                'remaining' => (float) $costBudget['remaining'],
                'percent' => $costBudget['percent'],
                'period_start' => $costBudget['period_start'],
                'period_end' => $costBudget['period_end'],
            ],
            'cost_summary' => $costSummary,
        ];
    }

    /**
     * Get usage breakdown by source (WhatsApp, Email, Web).
     */
    private function getUsageBySource(int $userId): array
    {
        $conn = $this->em->getConnection();

        $sql = '
            SELECT 
                BPROVIDER as source,
                BACTION as action,
                COUNT(*) as count
            FROM BUSELOG
            WHERE BUSERID = :user_id
            GROUP BY BPROVIDER, BACTION
            ORDER BY count DESC
        ';

        $results = $conn->fetchAllAssociative($sql, ['user_id' => $userId]);

        // Group by source
        $breakdown = [];
        foreach ($results as $row) {
            $source = $row['source'] ?: 'WEB';
            if (!isset($breakdown[$source])) {
                $breakdown[$source] = [
                    'total' => 0,
                    'actions' => [],
                ];
            }

            $breakdown[$source]['actions'][$row['action']] = (int) $row['count'];
            $breakdown[$source]['total'] += (int) $row['count'];
        }

        return $breakdown;
    }

    /**
     * Get usage breakdown by time period (today, this week, this month).
     */
    private function getUsageByTimePeriod(int $userId): array
    {
        $conn = $this->em->getConnection();

        $now = time();
        $todayStart = strtotime('today');
        $weekStart = strtotime('monday this week');
        $monthStart = strtotime('first day of this month');

        $periods = [
            'today' => $todayStart,
            'this_week' => $weekStart,
            'this_month' => $monthStart,
        ];

        $breakdown = [];

        foreach ($periods as $period => $timestamp) {
            $sql = '
                SELECT 
                    BACTION as action,
                    COUNT(*) as count
                FROM BUSELOG
                WHERE BUSERID = :user_id
                AND BUNIXTIMES >= :since
                GROUP BY BACTION
            ';

            $results = $conn->fetchAllAssociative($sql, [
                'user_id' => $userId,
                'since' => $timestamp,
            ]);

            $breakdown[$period] = [
                'total' => 0,
                'actions' => [],
            ];

            foreach ($results as $row) {
                $breakdown[$period]['actions'][$row['action']] = (int) $row['count'];
                $breakdown[$period]['total'] += (int) $row['count'];
            }
        }

        return $breakdown;
    }

    /**
     * Get recent usage entries.
     */
    private function getRecentUsage(int $userId, int $limit = 10): array
    {
        $conn = $this->em->getConnection();

        $limit = max(1, min(100, (int) $limit));

        $sql = "
            SELECT 
                BUNIXTIMES as timestamp,
                BACTION as action,
                BPROVIDER as source,
                BMODEL as model,
                BTOKENS as tokens,
                BPROMPT_TOKENS as prompt_tokens,
                BCOMPLETION_TOKENS as completion_tokens,
                BCACHED_TOKENS as cached_tokens,
                BCACHE_CREATION_TOKENS as cache_creation_tokens,
                BESTIMATED as estimated,
                BCOST as cost,
                BLATENCY as latency,
                BSTATUS as status
            FROM BUSELOG
            WHERE BUSERID = :user_id
            ORDER BY BUNIXTIMES DESC
            LIMIT {$limit}
        ";

        $results = $conn->fetchAllAssociative($sql, [
            'user_id' => $userId,
        ]);

        return array_map(function ($row) {
            return [
                'timestamp' => (int) $row['timestamp'],
                'datetime' => date('Y-m-d H:i:s', $row['timestamp']),
                'action' => $row['action'],
                'source' => $row['source'] ?: 'WEB',
                'model' => $row['model'],
                'tokens' => (int) $row['tokens'],
                'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
                'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
                'cached_tokens' => (int) ($row['cached_tokens'] ?? 0),
                'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
                'estimated' => (bool) ($row['estimated'] ?? false),
                'cost' => (float) $row['cost'],
                'latency' => (float) $row['latency'],
                'status' => $row['status'],
            ];
        }, $results);
    }

    /**
     * Get subscription info.
     */
    private function getSubscriptionInfo(User $user): array
    {
        $subscriptionData = $user->getSubscriptionData();
        $effectiveLevel = $user->getRateLimitLevel(); // Use effective level, not raw userLevel

        return [
            'level' => $effectiveLevel,
            'active' => $user->hasActiveSubscription(),
            'plan_name' => $this->getPlanName($effectiveLevel),
            'expires_at' => $subscriptionData['subscription_end'] ?? null,
            'stripe_customer_id' => $user->getStripeCustomerId(),
        ];
    }

    /**
     * Get friendly plan name.
     */
    private function getPlanName(string $level): string
    {
        return match ($level) {
            'ANONYMOUS' => 'Anonymous (Not Verified)',
            'NEW' => 'Free Plan',
            'PRO' => 'Pro Plan',
            'TEAM' => 'Team Plan',
            'BUSINESS' => 'Business Plan',
            default => 'Unknown',
        };
    }

    /**
     * Get overall stats for all users (admin only).
     */
    public function getOverallStats(string $period = 'all'): array
    {
        $conn = $this->em->getConnection();

        // Calculate date range
        $since = match ($period) {
            'day' => strtotime('today'),
            'week' => strtotime('monday this week'),
            'month' => strtotime('first day of this month'),
            default => 0, // All time
        };

        $whereClause = $since > 0 ? 'WHERE BUNIXTIMES >= :since' : '';
        $params = $since > 0 ? ['since' => $since] : [];

        // Total stats
        $sql = "
            SELECT 
                COUNT(*) as total_requests,
                SUM(BTOKENS) as total_tokens,
                SUM(BCOST) as total_cost,
                AVG(BLATENCY) as avg_latency
            FROM BUSELOG
            {$whereClause}
        ";

        $totals = $conn->fetchAssociative($sql, $params);

        // By action
        $sql = "
            SELECT 
                BACTION as action,
                COUNT(*) as count,
                SUM(BTOKENS) as tokens,
                SUM(BCOST) as cost
            FROM BUSELOG
            {$whereClause}
            GROUP BY BACTION
            ORDER BY count DESC
        ";

        $byAction = $conn->fetchAllAssociative($sql, $params);
        $byActionFormatted = [];
        foreach ($byAction as $row) {
            $byActionFormatted[$row['action']] = [
                'count' => (int) $row['count'],
                'tokens' => (int) $row['tokens'],
                'cost' => (float) $row['cost'],
            ];
        }

        // By provider
        $sql = "
            SELECT 
                BPROVIDER as provider,
                COUNT(*) as count,
                SUM(BTOKENS) as tokens,
                SUM(BCOST) as cost
            FROM BUSELOG
            {$whereClause}
            GROUP BY BPROVIDER
            ORDER BY count DESC
        ";

        $byProvider = $conn->fetchAllAssociative($sql, $params);
        $byProviderFormatted = [];
        foreach ($byProvider as $row) {
            $provider = $row['provider'] ?: 'WEB';
            $byProviderFormatted[$provider] = [
                'count' => (int) $row['count'],
                'tokens' => (int) $row['tokens'],
                'cost' => (float) $row['cost'],
            ];
        }

        // By model
        $modelWhereClause = $since > 0
            ? "WHERE BUNIXTIMES >= :since AND BMODEL IS NOT NULL AND BMODEL != ''"
            : "WHERE BMODEL IS NOT NULL AND BMODEL != ''";

        $sql = "
            SELECT 
                BMODEL as model,
                COUNT(*) as count,
                SUM(BTOKENS) as tokens,
                SUM(BCOST) as cost
            FROM BUSELOG
            {$modelWhereClause}
            GROUP BY BMODEL
            ORDER BY count DESC
            LIMIT 10
        ";

        $byModel = $conn->fetchAllAssociative($sql, $params);
        $byModelFormatted = [];
        foreach ($byModel as $row) {
            $byModelFormatted[$row['model']] = [
                'count' => (int) $row['count'],
                'tokens' => (int) $row['tokens'],
                'cost' => (float) $row['cost'],
            ];
        }

        return [
            'period' => $period,
            'total_requests' => (int) $totals['total_requests'],
            'total_tokens' => (int) ($totals['total_tokens'] ?? 0),
            'total_cost' => (float) ($totals['total_cost'] ?? 0),
            'avg_latency' => (float) ($totals['avg_latency'] ?? 0),
            'byAction' => $byActionFormatted,
            'byProvider' => $byProviderFormatted,
            'byModel' => $byModelFormatted,
        ];
    }

    /**
     * Get cost summary by time period.
     */
    private function getCostSummary(int $userId): array
    {
        $conn = $this->em->getConnection();

        $todayStart = (int) strtotime('today');
        $weekStart = (int) strtotime('monday this week');
        $monthStart = (int) strtotime('first day of this month');

        $periods = [
            'today' => $todayStart,
            'this_week' => $weekStart,
            'this_month' => $monthStart,
        ];

        $summary = [];

        foreach ($periods as $period => $since) {
            $row = $conn->fetchAssociative(
                'SELECT COALESCE(SUM(BCOST), 0) as total_cost
                 FROM BUSELOG WHERE BUSERID = :user_id AND BUNIXTIMES >= :since',
                ['user_id' => $userId, 'since' => $since]
            );

            $summary[$period] = (float) ($row['total_cost'] ?? 0);
        }

        $cacheSavingsRows = $conn->fetchAllAssociative(
            'SELECT BPRICE_SNAPSHOT, BCACHED_TOKENS
             FROM BUSELOG
             WHERE BUSERID = :user_id AND BUNIXTIMES >= :since AND BCACHED_TOKENS > 0',
            ['user_id' => $userId, 'since' => $monthStart]
        );

        $totalCacheSavings = 0.0;
        foreach ($cacheSavingsRows as $row) {
            $cachedTokens = (int) $row['BCACHED_TOKENS'];
            $snapshot = json_decode($row['BPRICE_SNAPSHOT'] ?? '{}', true);
            $priceIn = (float) ($snapshot['price_in'] ?? 0);
            $inUnit = $snapshot['in_unit'] ?? 'per1M';
            $cachePriceIn = isset($snapshot['cache_price_in']) ? (float) $snapshot['cache_price_in'] : null;

            if ($priceIn <= 0 || $cachedTokens <= 0) {
                continue;
            }

            $divisor = match ($inUnit) {
                'per1K' => 1_000,
                'per1' => 1,
                default => 1_000_000,
            };

            $fullCost = $cachedTokens * ($priceIn / $divisor);
            $actualCost = null !== $cachePriceIn
                ? $cachedTokens * ($cachePriceIn / $divisor)
                : $fullCost * 0.5;

            $totalCacheSavings += max(0, $fullCost - $actualCost);
        }

        $summary['cache_savings'] = round($totalCacheSavings, 6);

        return $summary;
    }

    /**
     * Get paginated, filterable activity log.
     *
     * @return array{items: list<array>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function getActivityLog(
        int $userId,
        int $page = 1,
        int $perPage = 20,
        ?string $search = null,
        ?string $action = null,
        ?int $from = null,
        ?int $to = null,
    ): array {
        $conn = $this->em->getConnection();
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $where = ['BUSERID = :user_id'];
        $params = ['user_id' => $userId];

        if ($search) {
            $where[] = '(BMODEL LIKE :search OR BPROVIDER LIKE :search)';
            $params['search'] = '%'.$search.'%';
        }

        if ($action) {
            $where[] = 'BACTION = :action';
            $params['action'] = $action;
        }

        if ($from) {
            $where[] = 'BUNIXTIMES >= :from_ts';
            $params['from_ts'] = $from;
        }

        if ($to) {
            $where[] = 'BUNIXTIMES <= :to_ts';
            $params['to_ts'] = $to;
        }

        $whereClause = implode(' AND ', $where);

        $total = (int) $conn->fetchOne(
            "SELECT COUNT(*) FROM BUSELOG WHERE {$whereClause}",
            $params,
        );

        $totalPages = max(1, (int) ceil($total / $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $conn->fetchAllAssociative(
            "SELECT
                BUNIXTIMES as timestamp,
                BACTION as action,
                BPROVIDER as source,
                BMODEL as model,
                BTOKENS as tokens,
                BPROMPT_TOKENS as prompt_tokens,
                BCOMPLETION_TOKENS as completion_tokens,
                BCACHED_TOKENS as cached_tokens,
                BCACHE_CREATION_TOKENS as cache_creation_tokens,
                BESTIMATED as estimated,
                BCOST as cost,
                BLATENCY as latency,
                BSTATUS as status
             FROM BUSELOG
             WHERE {$whereClause}
             ORDER BY BUNIXTIMES DESC
             LIMIT {$perPage} OFFSET {$offset}",
            $params,
        );

        $items = array_map(static fn (array $row) => [
            'timestamp' => (int) $row['timestamp'],
            'datetime' => date('Y-m-d H:i:s', (int) $row['timestamp']),
            'action' => $row['action'],
            'source' => $row['source'] ?: 'WEB',
            'model' => $row['model'],
            'tokens' => (int) $row['tokens'],
            'prompt_tokens' => (int) ($row['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($row['completion_tokens'] ?? 0),
            'cached_tokens' => (int) ($row['cached_tokens'] ?? 0),
            'cache_creation_tokens' => (int) ($row['cache_creation_tokens'] ?? 0),
            'estimated' => (bool) ($row['estimated'] ?? false),
            'cost' => (float) $row['cost'],
            'latency' => (float) $row['latency'],
            'status' => $row['status'],
        ], $rows);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages,
        ];
    }

    /**
     * Export usage data as CSV.
     */
    public function exportUsageAsCsv(User $user, ?int $sinceTimestamp = null): string
    {
        $userId = $user->getId();
        $conn = $this->em->getConnection();

        $sql = '
            SELECT 
                BUNIXTIMES as timestamp,
                BACTION as action,
                BPROVIDER as source,
                BMODEL as model,
                BTOKENS as tokens,
                BPROMPT_TOKENS as prompt_tokens,
                BCOMPLETION_TOKENS as completion_tokens,
                BCACHED_TOKENS as cached_tokens,
                BCACHE_CREATION_TOKENS as cache_creation_tokens,
                BESTIMATED as estimated,
                BCOST as cost,
                BLATENCY as latency,
                BSTATUS as status
            FROM BUSELOG
            WHERE BUSERID = :user_id
        ';

        $params = ['user_id' => $userId];

        if ($sinceTimestamp) {
            $sql .= ' AND BUNIXTIMES >= :since';
            $params['since'] = $sinceTimestamp;
        }

        $sql .= ' ORDER BY BUNIXTIMES DESC';

        $results = $conn->fetchAllAssociative($sql, $params);

        $csv = "Timestamp,Date,Action,Source,Model,Tokens,Prompt Tokens,Completion Tokens,Cached Tokens,Cache Creation Tokens,Estimated,Cost,Latency,Status\n";

        foreach ($results as $row) {
            $csv .= sprintf(
                "%d,%s,%s,%s,%s,%d,%d,%d,%d,%d,%s,%.6f,%.2f,%s\n",
                $row['timestamp'],
                date('Y-m-d H:i:s', $row['timestamp']),
                $row['action'],
                $row['source'] ?: 'WEB',
                $row['model'],
                $row['tokens'],
                $row['prompt_tokens'] ?? 0,
                $row['completion_tokens'] ?? 0,
                $row['cached_tokens'] ?? 0,
                $row['cache_creation_tokens'] ?? 0,
                $row['estimated'] ? 'Yes' : 'No',
                $row['cost'],
                $row['latency'],
                $row['status']
            );
        }

        return $csv;
    }
}
