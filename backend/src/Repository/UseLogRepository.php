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
