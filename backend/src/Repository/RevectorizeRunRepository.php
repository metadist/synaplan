<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RevectorizeRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RevectorizeRun>
 */
final class RevectorizeRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RevectorizeRun::class);
    }

    public function save(RevectorizeRun $run, bool $flush = true): void
    {
        $this->getEntityManager()->persist($run);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Most recent run for a given scope. Used to enforce the cooldown
     * between consecutive switches.
     */
    public function findLatestForScope(string $scope): ?RevectorizeRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.scope = :scope')
            ->setParameter('scope', $scope)
            ->orderBy('r.created', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findActive(): ?RevectorizeRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.status IN (:active)')
            ->setParameter('active', [
                RevectorizeRun::STATUS_QUEUED,
                RevectorizeRun::STATUS_RUNNING,
            ])
            ->orderBy('r.created', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Recent history for the admin run-history table.
     *
     * @return list<RevectorizeRun>
     */
    public function findRecent(int $limit = 50): array
    {
        /** @var list<RevectorizeRun> $rows */
        $rows = $this->createQueryBuilder('r')
            ->orderBy('r.created', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
