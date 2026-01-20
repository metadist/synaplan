<?php

declare(strict_types=1);

namespace Plugin\SortX\Repository;

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Plugin\SortX\Entity\SortxCategoryField;

/**
 * @extends ServiceEntityRepository<SortxCategoryField>
 */
class SortxCategoryFieldRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SortxCategoryField::class);
    }

    /**
     * Get all fields for a category, ordered by sort_order.
     *
     * @return SortxCategoryField[]
     */
    public function findByCategory(int $categoryId): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.category = :categoryId')
            ->setParameter('categoryId', $categoryId)
            ->orderBy('f.sortOrder', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find a specific field by category and key.
     */
    public function findOneByCategoryAndKey(int $categoryId, string $fieldKey): ?SortxCategoryField
    {
        return $this->createQueryBuilder('f')
            ->where('f.category = :categoryId')
            ->andWhere('f.fieldKey = :fieldKey')
            ->setParameter('categoryId', $categoryId)
            ->setParameter('fieldKey', $fieldKey)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
