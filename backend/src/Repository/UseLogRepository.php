<?php

namespace App\Repository;

use App\Entity\UseLog;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UseLog>
 */
class UseLogRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UseLog::class);
    }

    /**
     * Findet Usage-Logs für einen User in einem Zeitraum.
     */
    public function findByUserAndDateRange(int $userId, int $startTime, int $endTime): array
    {
        return $this->createQueryBuilder('u')
            ->where('u.userId = :userId')
            ->andWhere('u.unixTimestamp >= :start')
            ->andWhere('u.unixTimestamp <= :end')
            ->setParameter('userId', $userId)
            ->setParameter('start', $startTime)
            ->setParameter('end', $endTime)
            ->orderBy('u.unixTimestamp', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Berechnet Gesamtkosten für einen User.
     */
    public function getTotalCostByUser(int $userId, int $startTime, int $endTime): float
    {
        $result = $this->createQueryBuilder('u')
            ->select('SUM(u.cost) as totalCost')
            ->where('u.userId = :userId')
            ->andWhere('u.unixTimestamp >= :start')
            ->andWhere('u.unixTimestamp <= :end')
            ->setParameter('userId', $userId)
            ->setParameter('start', $startTime)
            ->setParameter('end', $endTime)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) ($result ?? 0);
    }

    /**
     * Zählt Actions pro Provider.
     */
    public function countByProviderAndAction(string $provider, string $action, int $startTime, int $endTime): int
    {
        return $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->where('u.provider = :provider')
            ->andWhere('u.action = :action')
            ->andWhere('u.unixTimestamp >= :start')
            ->andWhere('u.unixTimestamp <= :end')
            ->setParameter('provider', $provider)
            ->setParameter('action', $action)
            ->setParameter('start', $startTime)
            ->setParameter('end', $endTime)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Totals per published version and per day. Never includes user ids,
     * message ids or content — distinctUsers is a count.
     *
     * @return array{
     *   byVersion: list<array{agentVersionId: int|null, version: int|null, messages: int, tokens: int, cost: string, distinctUsers: int}>,
     *   byDay: list<array{day: string, messages: int, tokens: int, cost: string}>
     * }
     */
    public function aggregateForAgent(int $agentId, int $from, int $to): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $versionRows = $conn->fetchAllAssociative(
            'SELECT CAST(JSON_UNQUOTE(JSON_EXTRACT(l.BMETADATA, \'$.agentVersionId\')) AS UNSIGNED) AS agent_version_id,
                    v.BVERSION AS version,
                    COUNT(*) AS messages,
                    COALESCE(SUM(l.BTOKENS), 0) AS tokens,
                    COALESCE(SUM(l.BCOST), 0) AS cost,
                    COUNT(DISTINCT l.BUSERID) AS distinct_users
             FROM BUSELOG l
             LEFT JOIN BAGENTVERSIONS v ON v.BID = CAST(JSON_UNQUOTE(JSON_EXTRACT(l.BMETADATA, \'$.agentVersionId\')) AS UNSIGNED)
             WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(l.BMETADATA, \'$.agentId\')) AS UNSIGNED) = :agentId
               AND l.BUNIXTIMES >= :fromTs
               AND l.BUNIXTIMES <= :toTs
             GROUP BY agent_version_id, v.BVERSION
             ORDER BY v.BVERSION ASC',
            ['agentId' => $agentId, 'fromTs' => $from, 'toTs' => $to],
        );
        $dayRows = $conn->fetchAllAssociative(
            'SELECT FROM_UNIXTIME(l.BUNIXTIMES, \'%Y-%m-%d\') AS day,
                    COUNT(*) AS messages,
                    COALESCE(SUM(l.BTOKENS), 0) AS tokens,
                    COALESCE(SUM(l.BCOST), 0) AS cost
             FROM BUSELOG l
             WHERE CAST(JSON_UNQUOTE(JSON_EXTRACT(l.BMETADATA, \'$.agentId\')) AS UNSIGNED) = :agentId
               AND l.BUNIXTIMES >= :fromTs
               AND l.BUNIXTIMES <= :toTs
             GROUP BY day
             ORDER BY day ASC',
            ['agentId' => $agentId, 'fromTs' => $from, 'toTs' => $to],
        );

        $byVersion = [];
        foreach ($versionRows as $row) {
            $versionId = isset($row['agent_version_id']) ? (int) $row['agent_version_id'] : 0;
            $byVersion[] = [
                'agentVersionId' => $versionId > 0 ? $versionId : null,
                'version' => isset($row['version']) && '' !== (string) $row['version'] ? (int) $row['version'] : null,
                'messages' => (int) $row['messages'],
                'tokens' => (int) $row['tokens'],
                'cost' => (string) $row['cost'],
                'distinctUsers' => (int) $row['distinct_users'],
            ];
        }

        $byDay = [];
        foreach ($dayRows as $row) {
            $byDay[] = [
                'day' => (string) $row['day'],
                'messages' => (int) $row['messages'],
                'tokens' => (int) $row['tokens'],
                'cost' => (string) $row['cost'],
            ];
        }

        return ['byVersion' => $byVersion, 'byDay' => $byDay];
    }

    /**
     * The cost board (AdminUsageService): users ranked by what they cost in
     * a period, one page at a time, plus the user count for the pager.
     * $sort is one of cost|requests|tokens (validated by the caller and
     * mapped to a fixed column here — never user input in the ORDER BY).
     *
     * @return array{rows: list<array<string, mixed>>, totalUsers: int}
     */
    public function topUsersByUsage(int $since, string $sort, int $limit, int $offset): array
    {
        $orderBy = match ($sort) {
            'requests' => 'requests',
            'tokens' => 'tokens',
            default => 'cost',
        };
        $conn = $this->getEntityManager()->getConnection();
        $where = $since > 0 ? 'WHERE l.BUNIXTIMES >= :since' : '';
        $params = $since > 0 ? ['since' => $since] : [];

        $rows = $conn->fetchAllAssociative(
            "SELECT u.BID AS user_id, u.BMAIL AS email, u.BUSERLEVEL AS level,
                    u.BACCOUNTSTATUS AS account_status, u.BUSERDETAILS AS details,
                    COUNT(*) AS requests,
                    COALESCE(SUM(l.BTOKENS), 0) AS tokens,
                    COALESCE(SUM(l.BPROMPT_TOKENS), 0) AS prompt_tokens,
                    COALESCE(SUM(l.BCOMPLETION_TOKENS), 0) AS completion_tokens,
                    COALESCE(SUM(l.BCOST), 0) AS cost,
                    MAX(l.BUNIXTIMES) AS last_active
             FROM BUSELOG l
             INNER JOIN BUSER u ON u.BID = l.BUSERID
             {$where}
             GROUP BY u.BID, u.BMAIL, u.BUSERLEVEL, u.BACCOUNTSTATUS, u.BUSERDETAILS
             ORDER BY {$orderBy} DESC, user_id ASC
             LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $total = (int) $conn->fetchOne(
            'SELECT COUNT(DISTINCT BUSERID) FROM BUSELOG '.($since > 0 ? 'WHERE BUNIXTIMES >= :since' : ''),
            $params,
        );

        return ['rows' => $rows, 'totalUsers' => $total];
    }

    /**
     * Per-action totals for a page of users, with the agent flag the kind
     * mapping needs (an agent id or an agent-only channel in BMETADATA).
     *
     * @param list<int> $userIds
     *
     * @return list<array<string, mixed>>
     */
    public function actionTotalsForUsers(array $userIds, int $since): array
    {
        if ([] === $userIds) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();
        $params = ['ids' => $userIds];
        $where = 'l.BUSERID IN (:ids)';
        if ($since > 0) {
            $where .= ' AND l.BUNIXTIMES >= :since';
            $params['since'] = $since;
        }

        return $conn->fetchAllAssociative(
            "SELECT l.BUSERID AS user_id, l.BACTION AS action,
                    (COALESCE(CAST(JSON_VALUE(l.BMETADATA, '$.agentId') AS SIGNED), 0) > 0
                     OR COALESCE(CAST(JSON_VALUE(l.BMETADATA, '$.agent_id') AS SIGNED), 0) > 0
                     OR UPPER(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(l.BMETADATA, '$.source')), '')) IN ('AGENT', 'SAVED_TASK', 'MULTITASK')) AS has_agent,
                    COUNT(*) AS requests,
                    COALESCE(SUM(l.BTOKENS), 0) AS tokens,
                    COALESCE(SUM(l.BCOST), 0) AS cost
             FROM BUSELOG l
             WHERE {$where}
             GROUP BY user_id, action, has_agent",
            $params,
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER],
        );
    }

    /**
     * One page of a user's individual model requests, newest first — the
     * drill-down behind a cost-board row. Metadata stays a string here;
     * the caller decodes the few fields the board shows.
     *
     * @return array{rows: list<array<string, mixed>>, total: int}
     */
    public function requestsForUser(int $userId, int $since, int $limit, int $offset): array
    {
        $conn = $this->getEntityManager()->getConnection();
        $where = 'BUSERID = :user_id';
        $params = ['user_id' => $userId];
        if ($since > 0) {
            $where .= ' AND BUNIXTIMES >= :since';
            $params['since'] = $since;
        }

        $rows = $conn->fetchAllAssociative(
            "SELECT BID AS id, BUNIXTIMES AS ts, BACTION AS action, BPROVIDER AS provider,
                    BMODEL AS model, BTOKENS AS tokens, BPROMPT_TOKENS AS prompt_tokens,
                    BCOMPLETION_TOKENS AS completion_tokens, BCACHED_TOKENS AS cached_tokens,
                    BCOST AS cost, BLATENCY AS latency, BSTATUS AS status,
                    BESTIMATED AS estimated, BMETADATA AS metadata
             FROM BUSELOG
             WHERE {$where}
             ORDER BY BUNIXTIMES DESC, BID DESC
             LIMIT {$limit} OFFSET {$offset}",
            $params,
        );

        $total = (int) $conn->fetchOne("SELECT COUNT(*) FROM BUSELOG WHERE {$where}", $params);

        return ['rows' => $rows, 'total' => $total];
    }

    /**
     * Speichert einen UseLog.
     */
    public function save(UseLog $useLog, bool $flush = true): void
    {
        $this->getEntityManager()->persist($useLog);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
