<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\UserMemory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<UserMemory>
 */
final class UserMemoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, UserMemory::class);
    }

    public function save(UserMemory $memory, bool $flush = true): void
    {
        $this->getEntityManager()->persist($memory);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(UserMemory $memory, bool $flush = true): void
    {
        $this->getEntityManager()->remove($memory);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function flush(): void
    {
        $this->getEntityManager()->flush();
    }

    public function findForUser(int $memoryId, int $userId): ?UserMemory
    {
        return $this->findOneBy([
            'id' => $memoryId,
            'userId' => $userId,
            'active' => true,
        ]);
    }

    /**
     * @return list<UserMemory>
     */
    public function findActiveForUser(int $userId, ?string $category = null, ?string $namespace = null, int $limit = 1000): array
    {
        $qb = $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.active = true')
            ->setParameter('userId', $userId)
            ->orderBy('m.updated', 'DESC')
            ->setMaxResults($limit);

        if (null !== $category) {
            $qb->andWhere('m.category = :category')
                ->setParameter('category', $category);
        }
        if (null !== $namespace) {
            $qb->andWhere('m.namespace = :namespace')
                ->setParameter('namespace', $namespace);
        }

        /** @var list<UserMemory> $memories */
        $memories = $qb->getQuery()->getResult();

        return $memories;
    }

    /**
     * Of the given memory ids, return the subset that are active rows of the
     * user. Used to reconcile Qdrant retrieval hits against the SQL catalog so
     * a memory the UI cannot show is never used in a reply (#1570).
     *
     * @param list<int> $ids
     *
     * @return list<int>
     */
    public function filterActiveIds(int $userId, array $ids): array
    {
        if ([] === $ids) {
            return [];
        }

        /** @var list<array{id: int|string}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('m.id AS id')
            ->where('m.userId = :userId')
            ->andWhere('m.active = true')
            ->andWhere('m.id IN (:ids)')
            ->setParameter('userId', $userId)
            ->setParameter('ids', $ids)
            ->getQuery()
            ->getArrayResult();

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function countActiveForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->where('m.userId = :userId')
            ->andWhere('m.active = true')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<UserMemory>
     */
    public function findActiveBatchAfterId(int $afterId, int $limit): array
    {
        /** @var list<UserMemory> $memories */
        $memories = $this->createQueryBuilder('m')
            ->where('m.active = true')
            ->andWhere('m.id > :afterId')
            ->setParameter('afterId', $afterId)
            ->orderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $memories;
    }

    /**
     * @return list<string|null>
     */
    public function findActiveNamespaces(): array
    {
        /** @var list<array{namespace: string|null}> $rows */
        $rows = $this->createQueryBuilder('m')
            ->select('DISTINCT m.namespace AS namespace')
            ->where('m.active = true')
            ->getQuery()
            ->getArrayResult();

        return array_map(
            static fn (array $row): ?string => $row['namespace'],
            $rows,
        );
    }

    public function deleteAllForUser(int $userId): int
    {
        return $this->createQueryBuilder('m')
            ->delete()
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }
}
