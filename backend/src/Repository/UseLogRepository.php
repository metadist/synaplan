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
