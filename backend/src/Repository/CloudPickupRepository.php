<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudPickup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CloudPickup>
 */
class CloudPickupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CloudPickup::class);
    }

    public function deleteForUser(int $userId): void
    {
        $this->createQueryBuilder('p')
            ->delete()
            ->andWhere('p.ownerId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function save(CloudPickup $pickup): void
    {
        $this->getEntityManager()->persist($pickup);
        $this->getEntityManager()->flush();
    }

    /**
     * Fails only rows that are still open. A result that landed between the
     * check and the write is left alone.
     */
    public function failOpenOlderThan(int $createdBefore, string $errorCode): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE BCLOUDPICKUPS SET BSTATE = ?, BERRORCODE = ?, BFINISHED = ? WHERE BSTATE IN (?, ?) AND BCREATED < ?',
            [CloudPickup::STATE_FAILED, $errorCode, time(), CloudPickup::STATE_QUEUED, CloudPickup::STATE_CLAIMED, $createdBefore],
        );
    }

    public function countSince(int $ownerId, int $createdAfter): int
    {
        return (int) $this->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.ownerId = :owner')
            ->andWhere('p.created >= :after')
            ->setParameter('owner', $ownerId)
            ->setParameter('after', $createdAfter)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
