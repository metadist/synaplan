<?php

namespace App\Repository;

use App\Entity\Widget;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Widget>
 */
class WidgetRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Widget::class);
    }

    public function save(Widget $widget, bool $flush = false): void
    {
        $this->getEntityManager()->persist($widget);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(Widget $widget, bool $flush = false): void
    {
        $this->getEntityManager()->remove($widget);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find widget by widgetId (public ID)
     */
    public function findByWidgetId(string $widgetId): ?Widget
    {
        return $this->findOneBy(['widgetId' => $widgetId]);
    }

    /**
     * Alias for findByWidgetId
     */
    public function findOneByWidgetId(string $widgetId): ?Widget
    {
        return $this->findByWidgetId($widgetId);
    }

    /**
     * Find all widgets for a user
     */
    public function findByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->orderBy('w.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Alias for findByOwner
     */
    public function findByOwnerId(int $ownerId): array
    {
        return $this->findByOwner($ownerId);
    }

    /**
     * Find active widgets for a user
     */
    public function findActiveByOwner(int $ownerId): array
    {
        return $this->createQueryBuilder('w')
            ->where('w.ownerId = :ownerId')
            ->andWhere('w.status = :status')
            ->setParameter('ownerId', $ownerId)
            ->setParameter('status', 'active')
            ->orderBy('w.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count widgets for a user
     */
    public function countByOwner(int $ownerId): int
    {
        return $this->createQueryBuilder('w')
            ->select('COUNT(w.id)')
            ->where('w.ownerId = :ownerId')
            ->setParameter('ownerId', $ownerId)
            ->getQuery()
            ->getSingleScalarResult();
    }
}

