<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudWatchItem;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CloudWatchItem>
 */
class CloudWatchItemRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CloudWatchItem::class);
    }

    public function findVersion(int $watchId, string $sourceId, string $version): ?CloudWatchItem
    {
        $item = $this->findOneBy([
            'watchId' => $watchId,
            'sourceId' => $sourceId,
            'version' => $version,
        ]);

        return $item instanceof CloudWatchItem ? $item : null;
    }

    public function countSince(int $watchId, int $since): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.watchId = :watch')
            ->andWhere('i.created >= :since')
            ->andWhere('i.state != :skipped')
            ->setParameter('watch', $watchId)
            ->setParameter('since', $since)
            ->setParameter('skipped', CloudWatchItem::STATE_SKIPPED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @param list<int> $watchIds */
    public function countForWatchesSince(array $watchIds, int $since): int
    {
        if ([] === $watchIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.watchId IN (:watches)')
            ->andWhere('i.created >= :since')
            ->andWhere('i.state != :skipped')
            ->setParameter('watches', $watchIds)
            ->setParameter('since', $since)
            ->setParameter('skipped', CloudWatchItem::STATE_SKIPPED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CloudWatchItem> */
    public function waitingForSource(int $watchId, string $sourceId): array
    {
        /** @var list<CloudWatchItem> $rows */
        $rows = $this->findBy([
            'watchId' => $watchId,
            'sourceId' => $sourceId,
            'state' => CloudWatchItem::STATE_WAITING,
        ]);

        return $rows;
    }

    public function countWaiting(int $watchId): int
    {
        return (int) $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('i.watchId = :watch')
            ->andWhere('i.state = :waiting')
            ->setParameter('watch', $watchId)
            ->setParameter('waiting', CloudWatchItem::STATE_WAITING)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CloudWatchItem> */
    public function due(int $cutoff): array
    {
        /** @var list<CloudWatchItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.state = :waiting')
            ->andWhere('i.reason IS NULL')
            ->andWhere('i.updated <= :cutoff')
            ->setParameter('waiting', CloudWatchItem::STATE_WAITING)
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults(100)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<CloudWatchItem> */
    public function stuckRunning(int $cutoff): array
    {
        /** @var list<CloudWatchItem> $rows */
        $rows = $this->createQueryBuilder('i')
            ->where('i.state = :running')
            ->andWhere('i.updated <= :cutoff')
            ->setParameter('running', CloudWatchItem::STATE_RUNNING)
            ->setParameter('cutoff', $cutoff)
            ->setMaxResults(200)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}
