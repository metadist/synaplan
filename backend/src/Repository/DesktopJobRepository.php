<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DesktopJob;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DesktopJob>
 */
class DesktopJobRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DesktopJob::class);
    }

    /**
     * Oldest queued job a device may lease: one targeted at that device, or an
     * unassigned job (BDEVICEID IS NULL) owned by the same user.
     *
     * MUST be called inside a transaction — the PESSIMISTIC_WRITE lock is what
     * stops two simultaneous check-ins from leasing the same row (a second
     * check-in blocks, then sees `leased` and moves on).
     */
    public function findNextLeasable(int $ownerId, int $deviceId): ?DesktopJob
    {
        return $this->createQueryBuilder('j')
            ->where('j.ownerId = :ownerId')
            ->andWhere('j.status = :queued')
            ->andWhere('j.deviceId = :deviceId OR j.deviceId IS NULL')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('queued', DesktopJob::STATUS_QUEUED)
            ->setParameter('deviceId', $deviceId)
            ->orderBy('j.created', 'ASC')
            ->setMaxResults(1)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    public function findByLeaseToken(string $leaseToken): ?DesktopJob
    {
        if ('' === $leaseToken) {
            return null;
        }

        return $this->findOneBy(['leaseToken' => $leaseToken]);
    }

    public function findByOwnerIdempotency(int $ownerId, string $idempotency): ?DesktopJob
    {
        return $this->findOneBy(['ownerId' => $ownerId, 'idempotency' => $idempotency]);
    }

    public function findOwnedById(int $id, int $ownerId): ?DesktopJob
    {
        return $this->findOneBy(['id' => $id, 'ownerId' => $ownerId]);
    }

    /**
     * A user's most recent jobs, newest first (for the web "waiting/failed" card).
     *
     * @return list<DesktopJob>
     */
    public function findRecentByOwner(int $ownerId, int $limit = 50): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('j.created', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Leased jobs whose lease has expired — the reaper requeues or fails these.
     *
     * @return list<DesktopJob>
     */
    public function findExpiredLeases(int $now, int $limit = 100): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status = :leased')
            ->andWhere('j.leaseExpires < :now')
            ->setParameter('leased', DesktopJob::STATUS_LEASED)
            ->setParameter('now', $now)
            ->orderBy('j.leaseExpires', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Count non-terminal jobs waiting for a specific device (for the web
     * "jobs waiting" badge). Includes unassigned jobs owned by the user.
     */
    public function countPendingForDevice(int $ownerId, int $deviceId): int
    {
        return (int) $this->createQueryBuilder('j')
            ->select('COUNT(j.id)')
            ->where('j.ownerId = :ownerId')
            ->andWhere('j.status IN (:pending)')
            ->andWhere('j.deviceId = :deviceId OR j.deviceId IS NULL')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('pending', [DesktopJob::STATUS_QUEUED, DesktopJob::STATUS_LEASED])
            ->setParameter('deviceId', $deviceId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function save(DesktopJob $job, bool $flush = true): void
    {
        $this->getEntityManager()->persist($job);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
