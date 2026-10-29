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
     * Fails rows that are still queued or claimed. A confirmation that landed
     * between the check and the write is left alone. A claimed row that never
     * sent a result ages out on the same clock as one that was never claimed.
     */
    public function failOpenOlderThan(int $createdBefore, string $errorCode): int
    {
        return (int) $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE BCLOUDDELIVERIES SET BSTATE = ?, BERRORCODE = ?, BFINISHED = ? WHERE BSTATE IN (?, ?) AND BCREATED < ?',
            [
                CloudDelivery::STATE_FAILED,
                $errorCode,
                time(),
                CloudDelivery::STATE_QUEUED,
                CloudDelivery::STATE_CLAIMED,
                $createdBefore,
            ],
        );
    }

    /**
     * One claim wins. A second claim, or a row that is already finished, is left alone.
     */
    public function claimIfQueued(int $id): bool
    {
        return $this->updated(
            'UPDATE BCLOUDDELIVERIES SET BSTATE = ? WHERE BID = ? AND BSTATE = ?',
            [CloudDelivery::STATE_CLAIMED, $id, CloudDelivery::STATE_QUEUED],
            $id,
        );
    }

    /**
     * Records that the download ticket was consumed and the file was opened.
     * A second download, or a row that is not claimed, does not write again.
     */
    public function markDownloaded(int $id): bool
    {
        return $this->updated(
            'UPDATE BCLOUDDELIVERIES SET BDOWNLOADED = ? WHERE BID = ? AND BSTATE = ? AND BDOWNLOADED IS NULL',
            [time(), $id, CloudDelivery::STATE_CLAIMED],
            $id,
        );
    }

    /**
     * Sent, and only when the bytes were taken. A claimed row without that
     * mark stays open. A row the sweep already failed as unconfirmed can
     * still become sent when the mark is there. The first terminal state wins.
     */
    public function recordDelivered(int $id, ?string $path, ?string $remoteId, ?string $url, string $unconfirmedCode): bool
    {
        return $this->updated(
            'UPDATE BCLOUDDELIVERIES SET BSTATE = ?, BREMOTEPATH = ?, BREMOTEID = ?, BOPENURL = ?, BERRORCODE = NULL, BFINISHED = ? WHERE BID = ? AND BDOWNLOADED IS NOT NULL AND (BSTATE = ? OR (BSTATE = ? AND BERRORCODE = ?))',
            [
                CloudDelivery::STATE_DELIVERED,
                $path,
                $remoteId,
                $url,
                time(),
                $id,
                CloudDelivery::STATE_CLAIMED,
                CloudDelivery::STATE_FAILED,
                $unconfirmedCode,
            ],
            $id,
        );
    }

    /**
     * A result fails a claimed row. The notify may also fail one that is still
     * queued, because that answer is the cloud's own error. Delivered and
     * already-failed rows are left alone.
     */
    public function recordFailed(int $id, string $errorCode, bool $includeQueued): bool
    {
        $sql = $includeQueued
            ? 'UPDATE BCLOUDDELIVERIES SET BSTATE = ?, BERRORCODE = ?, BFINISHED = ? WHERE BID = ? AND BSTATE IN (?, ?)'
            : 'UPDATE BCLOUDDELIVERIES SET BSTATE = ?, BERRORCODE = ?, BFINISHED = ? WHERE BID = ? AND BSTATE = ?';
        $params = $includeQueued
            ? [CloudDelivery::STATE_FAILED, $errorCode, time(), $id, CloudDelivery::STATE_QUEUED, CloudDelivery::STATE_CLAIMED]
            : [CloudDelivery::STATE_FAILED, $errorCode, time(), $id, CloudDelivery::STATE_CLAIMED];

        return $this->updated($sql, $params, $id);
    }

    /**
     * Reloads a managed row so a later flush cannot write a stale copy back
     * over the conditional update.
     */
    public function refreshDelivery(CloudDelivery $delivery): void
    {
        if ($this->getEntityManager()->contains($delivery)) {
            $this->getEntityManager()->refresh($delivery);
        }
    }

    /**
     * @param list<mixed> $params
     */
    private function updated(string $sql, array $params, int $id): bool
    {
        $changed = 1 === (int) $this->getEntityManager()->getConnection()->executeStatement($sql, $params);
        if ($changed) {
            $delivery = $this->find($id);
            if ($delivery instanceof CloudDelivery) {
                $this->refreshDelivery($delivery);
            }
        }

        return $changed;
    }
}
