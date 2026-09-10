<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Approval;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Approval>
 */
class ApprovalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Approval::class);
    }

    public function save(Approval $approval, bool $flush = true): void
    {
        $this->getEntityManager()->persist($approval);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function findOneForOwner(int $id, int $ownerId): ?Approval
    {
        $row = $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);

        return $row instanceof Approval ? $row : null;
    }

    /**
     * @return list<Approval>
     */
    public function findForOwnerByStatus(int $ownerId, string $statusGroup, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('a')
            ->where('a.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('a.created', 'DESC')
            ->setMaxResults($limit);

        if ('pending' === $statusGroup) {
            $qb->andWhere('a.status = :status')->setParameter('status', Approval::STATUS_PENDING);
        } else {
            $qb->andWhere('a.status IN (:statuses)')
                ->setParameter('statuses', [
                    Approval::STATUS_APPROVED,
                    Approval::STATUS_REJECTED,
                    Approval::STATUS_EXPIRED,
                    Approval::STATUS_EXECUTED,
                    Approval::STATUS_FAILED,
                ]);
        }

        return $qb->getQuery()->getResult();
    }

    public function countPendingForOwner(int $ownerId): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->where('a.ownerId = :ownerId')
            ->andWhere('a.status = :status')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', Approval::STATUS_PENDING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Approval>
     */
    public function findExpiredPending(int $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.expiresAt < :now')
            ->setParameter('status', Approval::STATUS_PENDING)
            ->setParameter('now', $now)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * @return list<Approval>
     */
    public function findPending(int $limit = 500): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->setParameter('status', Approval::STATUS_PENDING)
            ->orderBy('a.created', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }
}
