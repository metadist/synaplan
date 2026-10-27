<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudWatch;
use App\Entity\CloudWatchItem;
use App\Entity\ExternalIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\ParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<CloudWatch>
 */
class CloudWatchRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, CloudWatch::class);
    }

    public function stamp(CloudWatch $watch, string $instanceId): void
    {
        $revision = $this->nextRevision($instanceId);
        $watch->setRevision($revision);
        if (CloudWatch::STATE_STOPPED === $watch->getState() && null !== $watch->getId()) {
            $this->remember($instanceId, (int) $watch->getId(), $revision);
        }
    }

    public function deleteByOwner(int $ownerId): void
    {
        /** @var list<array{id: int|string, instanceId: string}> $rows */
        $rows = $this->createQueryBuilder('w')
            ->select('w.id AS id, i.instanceId AS instanceId')
            ->innerJoin(ExternalIdentity::class, 'i', 'WITH', 'i.id = w.identityId')
            ->where('w.ownerId = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->getArrayResult();
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $ids[] = $id;
            $this->remember((string) $row['instanceId'], $id, $this->nextRevision((string) $row['instanceId']));
        }
        if ([] !== $ids) {
            $this->getEntityManager()->createQueryBuilder()
                ->delete(CloudWatchItem::class, 'i')
                ->where('i.watchId IN (:ids)')
                ->setParameter('ids', $ids, ArrayParameterType::INTEGER)
                ->getQuery()
                ->execute();
        }
        $this->createQueryBuilder('w')
            ->delete()
            ->where('w.ownerId = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->execute();
    }

    public function countOpenForOwner(int $ownerId): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.ownerId = :owner')
            ->andWhere('w.state != :stopped')
            ->setParameter('owner', $ownerId)
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countOpenForInstance(string $instanceId): int
    {
        return (int) $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->innerJoin(ExternalIdentity::class, 'i', 'WITH', 'i.id = w.identityId')
            ->where('i.instanceId = :instance')
            ->andWhere('w.state != :stopped')
            ->setParameter('instance', $instanceId)
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** @return list<CloudWatch> */
    public function forOwnerInstance(int $ownerId, string $instanceId): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->createQueryBuilder('w')
            ->innerJoin(ExternalIdentity::class, 'i', 'WITH', 'i.id = w.identityId')
            ->where('w.ownerId = :owner')
            ->andWhere('i.instanceId = :instance')
            ->andWhere('w.state != :stopped')
            ->setParameter('owner', $ownerId)
            ->setParameter('instance', $instanceId)
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->orderBy('w.id', 'DESC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function findForOwner(int $id, int $ownerId): ?CloudWatch
    {
        $watch = $this->find($id);

        return $watch instanceof CloudWatch && $watch->getOwnerId() === $ownerId ? $watch : null;
    }

    /** @return list<CloudWatch> */
    public function forIdentity(int $identityId): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->findBy(['identityId' => $identityId]);

        return $rows;
    }

    /**
     * @param list<string> $targetIds
     *
     * @return list<CloudWatch>
     */
    public function touching(int $ownerId, string $instanceId, array $targetIds): array
    {
        if ('' === $instanceId || [] === $targetIds) {
            return [];
        }

        /** @var list<CloudWatch> $rows */
        $rows = $this->createQueryBuilder('w')
            ->innerJoin(ExternalIdentity::class, 'i', 'WITH', 'i.id = w.identityId')
            ->where('w.ownerId = :owner')
            ->andWhere('i.instanceId = :instance')
            ->andWhere('w.targetId IN (:ids)')
            ->andWhere('w.state != :stopped')
            ->setParameter('owner', $ownerId)
            ->setParameter('instance', $instanceId)
            ->setParameter('ids', $targetIds)
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->orderBy('w.id', 'ASC')
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<CloudWatch> */
    public function page(string $instanceId, int $afterId, int $boundary, int $limit): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->scoped($instanceId)
            ->andWhere('w.state != :stopped')
            ->andWhere('w.revision <= :boundary')
            ->andWhere('w.id > :after')
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->setParameter('boundary', $boundary)
            ->setParameter('after', $afterId)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<CloudWatch> */
    public function changedSince(string $instanceId, int $revision, int $limit): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->scoped($instanceId)
            ->andWhere('w.revision > :revision')
            ->andWhere('w.state != :stopped')
            ->setParameter('revision', $revision)
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->orderBy('w.revision', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<CloudWatch> */
    public function targets(string $instanceId, string $targetId): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->scoped($instanceId)
            ->andWhere('w.targetId = :target')
            ->andWhere('w.state != :stopped')
            ->setParameter('target', $targetId)
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<int> */
    public function openIds(string $instanceId): array
    {
        /** @var list<int|string> $ids */
        $ids = $this->scoped($instanceId)
            ->select('w.id')
            ->andWhere('w.state != :stopped')
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(static fn (int|string $id): int => (int) $id, $ids);
    }

    public function currentRevision(string $instanceId): int
    {
        $value = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT BREVISION FROM BCLOUDWATCHFEED WHERE BINSTANCEID = ?',
            [$instanceId],
        );

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<array{id: int, revision: int}>
     */
    public function tombstonesSince(string $instanceId, int $revision, int $limit): array
    {
        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT BWATCHID AS id, BREVISION AS revision FROM BCLOUDWATCHTOMBSTONES WHERE BINSTANCEID = ? AND BREVISION > ? ORDER BY BREVISION ASC LIMIT ?',
            [$instanceId, $revision, $limit],
            [ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'revision' => (int) $row['revision']];
        }

        return $out;
    }

    public function nextRevision(string $instanceId): int
    {
        $connection = $this->getEntityManager()->getConnection();
        $own = 0 === $connection->getTransactionNestingLevel();
        if ($own) {
            $connection->beginTransaction();
        }
        try {
            $connection->executeStatement(
                'INSERT INTO BCLOUDWATCHFEED (BINSTANCEID, BREVISION) VALUES (?, 0) ON DUPLICATE KEY UPDATE BINSTANCEID = BINSTANCEID',
                [$instanceId],
            );
            $current = $connection->fetchOne(
                'SELECT BREVISION FROM BCLOUDWATCHFEED WHERE BINSTANCEID = ? FOR UPDATE',
                [$instanceId],
            );
            $revision = (is_numeric($current) ? (int) $current : 0) + 1;
            $connection->executeStatement(
                'UPDATE BCLOUDWATCHFEED SET BREVISION = ? WHERE BINSTANCEID = ?',
                [$revision, $instanceId],
            );
            if ($own) {
                $connection->commit();
            }

            return $revision;
        } catch (\Throwable $e) {
            if ($own && $connection->isTransactionActive()) {
                $connection->rollBack();
            }
            throw $e;
        }
    }

    private function remember(string $instanceId, int $watchId, int $revision): void
    {
        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT INTO BCLOUDWATCHTOMBSTONES (BINSTANCEID, BWATCHID, BREVISION, BCREATED) VALUES (?, ?, ?, ?)',
            [$instanceId, $watchId, $revision, time()],
        );
    }

    private function scoped(string $instanceId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('w')
            ->innerJoin(ExternalIdentity::class, 'i', 'WITH', 'i.id = w.identityId')
            ->where('i.instanceId = :instance')
            ->setParameter('instance', $instanceId);
    }
}
