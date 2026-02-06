<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WidgetSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WidgetSummary>
 */
class WidgetSummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetSummary::class);
    }

    public function save(WidgetSummary $summary, bool $flush = false): void
    {
        $this->getEntityManager()->persist($summary);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find summary for a specific widget and date.
     */
    public function findByWidgetAndDate(string $widgetId, int $date): ?WidgetSummary
    {
        return $this->findOneBy([
            'widgetId' => $widgetId,
            'date' => $date,
        ]);
    }

    /**
     * Get recent summaries for a widget.
     *
     * @return WidgetSummary[]
     */
    public function findRecentByWidget(string $widgetId, int $limit = 7): array
    {
        return $this->createQueryBuilder('ws')
            ->where('ws.widgetId = :widgetId')
            ->setParameter('widgetId', $widgetId)
            ->orderBy('ws.date', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Get summaries for a widget in a date range.
     *
     * @return WidgetSummary[]
     */
    public function findByWidgetAndDateRange(string $widgetId, int $fromDate, int $toDate): array
    {
        return $this->createQueryBuilder('ws')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.date >= :fromDate')
            ->andWhere('ws.date <= :toDate')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('fromDate', $fromDate)
            ->setParameter('toDate', $toDate)
            ->orderBy('ws.date', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
