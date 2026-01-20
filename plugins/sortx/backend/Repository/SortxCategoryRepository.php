<?php

declare(strict_types=1);

namespace Plugin\SortX\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Plugin\SortX\Entity\SortxCategory;

/**
 * @extends ServiceEntityRepository<SortxCategory>
 */
class SortxCategoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SortxCategory::class);
    }

    /**
     * Get all enabled categories for a user, ordered by sort_order.
     *
     * @return SortxCategory[]
     */
    public function findEnabledByUser(int $userId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :userId')
            ->andWhere('c.enabled = true')
            ->setParameter('userId', $userId)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get all categories for a user (including disabled), ordered by sort_order.
     *
     * @return SortxCategory[]
     */
    public function findAllByUser(int $userId): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific category by user and key.
     */
    public function findOneByUserAndKey(int $userId, string $key): ?SortxCategory
    {
        return $this->createQueryBuilder('c')
            ->where('c.userId = :userId')
            ->andWhere('c.key = :key')
            ->setParameter('userId', $userId)
            ->setParameter('key', $key)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Check if user has any categories configured.
     */
    public function userHasCategories(int $userId): bool
    {
        $count = $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->where('c.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
