<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WidgetEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WidgetEvent>
 */
class WidgetEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetEvent::class);
    }

    public function save(WidgetEvent $event, bool $flush = false): void
    {
        $this->getEntityManager()->persist($event);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Get new events for a session since a given event ID.
     *
     * @return WidgetEvent[]
     */
    public function findNewEvents(string $widgetId, string $sessionId, int $lastEventId = 0): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.widgetId = :widgetId')
            ->andWhere('e.sessionId = :sessionId')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('sessionId', $sessionId)
            ->orderBy('e.id', 'ASC');

        if ($lastEventId > 0) {
            $qb->andWhere('e.id > :lastEventId')
                ->setParameter('lastEventId', $lastEventId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Get new notification events for a widget owner.
     *
     * @return WidgetEvent[]
     */
    public function findNewNotifications(string $widgetId, int $lastEventId = 0): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.widgetId = :widgetId')
            ->andWhere('e.type = :type')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('type', 'notification')
            ->orderBy('e.id', 'ASC');

        if ($lastEventId > 0) {
            $qb->andWhere('e.id > :lastEventId')
                ->setParameter('lastEventId', $lastEventId);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Clean up old events (older than 24 hours).
     */
    public function cleanupOldEvents(): int
    {
        $cutoff = time() - 86400; // 24 hours

        return $this->createQueryBuilder('e')
            ->delete()
            ->where('e.created < :cutoff')
            ->setParameter('cutoff', $cutoff)
            ->getQuery()
            ->execute();
    }
}
