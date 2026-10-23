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

    /**
     * Ends one pickup only while it is still open, so a result and a run that
     * gave up cannot overwrite each other: whichever lands first wins. Counts
     * left null keep what the row already holds.
     *
     * @param list<array<mixed>>|null $skipped
     */
    public function finishIfOpen(int $id, string $state, ?string $errorCode, ?int $listed = null, ?int $sent = null, ?array $skipped = null): bool
    {
        return 1 === (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE BCLOUDPICKUPS SET BSTATE = ?, BERRORCODE = ?, BFINISHED = ?, BLISTED = COALESCE(?, BLISTED), BSENT = COALESCE(?, BSENT), BSKIPPED = COALESCE(?, BSKIPPED) WHERE BID = ? AND BSTATE IN (?, ?)',
            [
                $state,
                $errorCode,
                time(),
                $listed,
                $sent,
                null !== $skipped ? json_encode($skipped, JSON_THROW_ON_ERROR) : null,
                $id,
                CloudPickup::STATE_QUEUED,
                CloudPickup::STATE_CLAIMED,
            ],
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
