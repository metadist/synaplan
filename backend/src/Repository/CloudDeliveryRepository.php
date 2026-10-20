<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CloudDelivery>
 */
class CloudDeliveryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CloudDelivery::class);
    }

    public function deleteForUser(int $userId): void
    {
        $this->createQueryBuilder('d')
            ->delete()
            ->andWhere('d.ownerId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function save(CloudDelivery $delivery): void
    {
        $this->getEntityManager()->persist($delivery);
        $this->getEntityManager()->flush();
    }

    /**
     * Fails only rows that are still queued. A confirmation that landed
     * between the check and the write is left alone.
     */
    public function failQueuedOlderThan(int $createdBefore, string $errorCode): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE BCLOUDDELIVERIES SET BSTATE = ?, BERRORCODE = ?, BFINISHED = ? WHERE BSTATE = ? AND BCREATED < ?',
            [CloudDelivery::STATE_FAILED, $errorCode, time(), CloudDelivery::STATE_QUEUED, $createdBefore],
        );
    }
}
