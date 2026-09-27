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

    /**
     * The job holding this lease token, locked for update.
     *
     * MUST be called inside a transaction. The row lock stops a cancel from
     * committing between this read and the result write (which would otherwise
     * record a result for a job the person already stopped).
     */
    public function findByLeaseToken(string $leaseToken): ?DesktopJob
    {
        if ('' === $leaseToken) {
            return null;
        }

        return $this->createQueryBuilder('j')
            ->where('j.leaseToken = :token')
            ->setParameter('token', $leaseToken)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * One owner-scoped job, locked so a lease cannot start while it is cancelled.
     *
     * MUST be called inside a transaction.
     */
    public function findOwnedForUpdate(int $id, int $ownerId): ?DesktopJob
    {
        return $this->createQueryBuilder('j')
            ->where('j.id = :id')
            ->andWhere('j.ownerId = :ownerId')
            ->setParameter('id', $id)
            ->setParameter('ownerId', $ownerId)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getOneOrNullResult();
    }

    /**
     * Queued and leased jobs targeted at one device, locked for cancellation.
     *
     * Unassigned jobs (any of the user's computers may pick them up) are not
     * included. MUST be called inside a transaction.
     *
     * @return list<DesktopJob>
     */
    public function findOpenForDevice(int $ownerId, int $deviceId): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.ownerId = :ownerId')
            ->andWhere('j.deviceId = :deviceId')
            ->andWhere('j.status IN (:open)')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('deviceId', $deviceId)
            ->setParameter('open', [DesktopJob::STATUS_QUEUED, DesktopJob::STATUS_LEASED])
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
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
     * Queued jobs that have waited past the queued TTL. `updated` is refreshed
     * when a lease is returned to the queue, so a retry gets a fresh window.
     *
     * MUST be called inside a transaction. The row lock stops a check-in from
     * leasing the same job before the reaper commits.
     *
     * @return list<DesktopJob>
     */
    public function findStaleQueued(int $cutoff, int $limit = 100): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status = :queued')
            ->andWhere('(j.updated > 0 AND j.updated < :cutoff) OR (j.updated = 0 AND j.created < :cutoff)')
            ->setParameter('queued', DesktopJob::STATUS_QUEUED)
            ->setParameter('cutoff', $cutoff)
            ->orderBy('j.created', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->getResult();
    }

    /**
     * Leased jobs whose lease has expired — the reaper requeues or fails these.
     *
     * MUST be called inside a transaction so the row lock is held until commit.
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
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
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
