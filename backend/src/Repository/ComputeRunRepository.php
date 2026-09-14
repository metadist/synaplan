<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComputeRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComputeRun>
 */
class ComputeRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComputeRun::class);
    }

    public function save(ComputeRun $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    public function findQueuedByApproval(int $approvalId): ?ComputeRun
    {
        $found = $this->findOneBy([
            'approvalId' => $approvalId,
            'status' => ComputeRun::STATUS_QUEUED,
        ]);

        return $found instanceof ComputeRun ? $found : null;
    }

    public function countActiveForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.userId = :userId')
            ->andWhere('r.status IN (:open)')
            ->setParameter('userId', $userId)
            ->setParameter('open', [ComputeRun::STATUS_QUEUED, ComputeRun::STATUS_RUNNING])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function sumDurationMsSince(int $userId, \DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.durationMs), 0)')
            ->where('r.userId = :userId')
            ->andWhere('r.created >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
