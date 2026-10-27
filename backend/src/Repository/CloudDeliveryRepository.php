<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudDelivery;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    /**
     * @param list<int> $fileIds
     *
     * @return list<CloudDelivery>
     */
    public function findForOwnerFiles(int $ownerId, array $fileIds): array
    {
        if ([] === $fileIds) {
            return [];
        }

        /** @var list<CloudDelivery> $rows */
        $rows = $this->createQueryBuilder('d')
            ->where('d.ownerId = :owner')
            ->andWhere('d.fileId IN (:ids)')
            ->setParameter('owner', $ownerId)
            ->setParameter('ids', $fileIds, ArrayParameterType::INTEGER)
            ->orderBy('d.created', 'DESC')
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $rows;
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

    public function writtenRecently(int $identityId, string $remoteId, int $since): bool
    {
        $count = $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.identityId = :identity')
            ->andWhere('d.remoteId = :remote')
            ->andWhere('d.created >= :since')
            ->setParameter('identity', $identityId)
            ->setParameter('remote', $remoteId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $count > 0;
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
