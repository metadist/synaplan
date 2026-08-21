<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\SavedTaskRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SavedTaskRun>
 */
class SavedTaskRunRepository extends ServiceEntityRepository
{
    public const RETAIN_MAX = 50;
    public const RETAIN_DAYS = 90;

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SavedTaskRun::class);
    }

    /**
     * @return list<SavedTaskRun>
     */
    public function findRecentForTask(int $savedTaskId, int $limit = 20, int $offset = 0): array
    {
        return $this->createQueryBuilder('r')
            ->where('r.savedTaskId = :taskId')
            ->setParameter('taskId', $savedTaskId)
            ->orderBy('r.created', 'DESC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function countForTask(int $savedTaskId): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.savedTaskId = :taskId')
            ->setParameter('taskId', $savedTaskId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function deleteForTask(int $savedTaskId): void
    {
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.savedTaskId = :taskId')
            ->setParameter('taskId', $savedTaskId)
            ->getQuery()
            ->execute();
    }

    public function prune(int $savedTaskId, \DateTimeImmutable $now): void
    {
        $cutoff = $now->modify('-'.self::RETAIN_DAYS.' days')->getTimestamp();
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.savedTaskId = :taskId')
            ->andWhere('r.created < :cutoff')
            ->setParameter('taskId', $savedTaskId)
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();

        $keep = $this->findRecentForTask($savedTaskId, self::RETAIN_MAX);
        if (count($keep) < self::RETAIN_MAX) {
            return;
        }
        $oldestKept = $keep[array_key_last($keep)]->getCreated();
        $this->createQueryBuilder('r')
            ->delete()
            ->where('r.savedTaskId = :taskId')
            ->andWhere('r.created < :oldest')
            ->setParameter('taskId', $savedTaskId)
            ->setParameter('oldest', $oldestKept)
            ->getQuery()
            ->execute();
    }

    public function save(SavedTaskRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
