<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\CloudWatch;
use App\Entity\CloudWatchItem;
use App\Entity\ExternalIdentity;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
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

    public function deleteByOwner(int $ownerId): void
    {
        /** @var list<int|string> $ids */
        $ids = $this->createQueryBuilder('w')
            ->select('w.id')
            ->where('w.ownerId = :owner')
            ->setParameter('owner', $ownerId)
            ->getQuery()
            ->getSingleColumnResult();
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
    public function page(string $instanceId, int $afterId, int $limit): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->scoped($instanceId)
            ->andWhere('w.state != :stopped')
            ->andWhere('w.id > :after')
            ->setParameter('stopped', CloudWatch::STATE_STOPPED)
            ->setParameter('after', $afterId)
            ->orderBy('w.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    /** @return list<CloudWatch> */
    public function changedSince(string $instanceId, int $updated, int $id, int $limit): array
    {
        /** @var list<CloudWatch> $rows */
        $rows = $this->scoped($instanceId)
            ->andWhere('w.updated > :updated OR (w.updated = :updated AND w.id > :id)')
            ->setParameter('updated', $updated)
            ->setParameter('id', $id)
            ->orderBy('w.updated', 'ASC')
            ->addOrderBy('w.id', 'ASC')
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

    public function highWater(string $instanceId): int
    {
        $value = $this->scoped($instanceId)
            ->select('MAX(w.updated)')
            ->getQuery()
            ->getSingleScalarResult();

        return is_numeric($value) ? (int) $value : 0;
    }

    private function scoped(string $instanceId): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('w')
            ->innerJoin(ExternalIdentity::class, 'i', 'WITH', 'i.id = w.identityId')
            ->where('i.instanceId = :instance')
            ->setParameter('instance', $instanceId);
    }
}
