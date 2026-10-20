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
     * @return list<CloudDelivery>
     */
    public function findQueuedOlderThan(int $createdBefore): array
    {
        /** @var list<CloudDelivery> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.state = :state')
            ->andWhere('d.created < :before')
            ->setParameter('state', CloudDelivery::STATE_QUEUED)
            ->setParameter('before', $createdBefore)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
