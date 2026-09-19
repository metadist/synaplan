<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ComputeWorkspace;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ComputeWorkspace>
 */
class ComputeWorkspaceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ComputeWorkspace::class);
    }

    public function findActiveForUser(int $userId): ?ComputeWorkspace
    {
        $found = $this->findOneBy([
            'userId' => $userId,
            'status' => ComputeWorkspace::STATUS_ACTIVE,
        ]);

        return $found instanceof ComputeWorkspace ? $found : null;
    }

    /**
     * Active workspaces idle past the TTL. Never-used workspaces (no
     * lastUsed yet) age from creation.
     *
     * @return list<ComputeWorkspace>
     */
    public function findStaleActiveWorkspaces(\DateTimeImmutable $lastUsedBefore): array
    {
        /* @var list<ComputeWorkspace> */
        return $this->createQueryBuilder('w')
            ->where('w.status = :active')
            ->andWhere('COALESCE(w.lastUsed, w.created) < :before')
            ->setParameter('active', ComputeWorkspace::STATUS_ACTIVE)
            ->setParameter('before', $lastUsedBefore)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<ComputeWorkspace>
     */
    public function findExpiredWorkspaces(\DateTimeImmutable $now): array
    {
        /* @var list<ComputeWorkspace> */
        return $this->createQueryBuilder('w')
            ->where('w.status = :expiring')
            ->andWhere('w.expiresAt IS NOT NULL')
            ->andWhere('w.expiresAt <= :now')
            ->setParameter('expiring', ComputeWorkspace::STATUS_EXPIRING)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }

    public function save(ComputeWorkspace $workspace): void
    {
        $this->getEntityManager()->persist($workspace);
        $this->getEntityManager()->flush();
    }

    public function remove(ComputeWorkspace $workspace): void
    {
        $this->getEntityManager()->remove($workspace);
        $this->getEntityManager()->flush();
    }
}
