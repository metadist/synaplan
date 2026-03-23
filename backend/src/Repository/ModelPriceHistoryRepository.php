<?php

namespace App\Repository;

use App\Entity\Model;
use App\Entity\ModelPriceHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ModelPriceHistory>
 */
class ModelPriceHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ModelPriceHistory::class);
    }

    /**
     * Find the price valid at a given timestamp for a model.
     */
    public function findPriceAtTimestamp(Model $model, \DateTimeInterface $timestamp): ?ModelPriceHistory
    {
        return $this->createQueryBuilder('mph')
            ->where('mph.model = :model')
            ->andWhere('mph.validFrom <= :timestamp')
            ->andWhere('mph.validTo IS NULL OR mph.validTo > :timestamp')
            ->setParameter('model', $model)
            ->setParameter('timestamp', $timestamp)
            ->orderBy('mph.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the currently active price entry for a model.
     */
    public function findCurrentPrice(Model $model): ?ModelPriceHistory
    {
        return $this->createQueryBuilder('mph')
            ->where('mph.model = :model')
            ->andWhere('mph.validTo IS NULL')
            ->setParameter('model', $model)
            ->orderBy('mph.validFrom', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Close the current price entry by setting validTo.
     */
    public function closeCurrentPrice(Model $model, \DateTimeInterface $closedAt): void
    {
        $current = $this->findCurrentPrice($model);
        if ($current) {
            $current->setValidTo($closedAt);
        }
    }
}
