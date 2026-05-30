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
    /**
     * Internal event type used to carry the operator's "is typing" indicator.
     * It is delivered to the widget through a dedicated path (latest-wins), so
     * it is excluded from the normal event stream to avoid double handling.
     */
    public const OPERATOR_TYPING_TYPE = '_operator_typing';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetEvent::class);
    }

    public function add(WidgetEvent $event, bool $flush = true): void
    {
        $em = $this->getEntityManager();
        $em->persist($event);
        if ($flush) {
            $em->flush();
        }
    }

    /**
     * Stream events for a session newer than $lastEventId.
     *
     * Ordered by the auto-increment id. Under Galera, ids are assigned per node
     * (interleaved offsets), so id order is not a strict commit order — a row
     * committed slightly later on another node can carry a *lower* id than one
     * already read, and a pure `id > lastEventId` cursor would skip it forever.
     *
     * To close that gap, callers pass a $graceCutoff (unix seconds): in addition
     * to `id > lastEventId`, we also return any non-expired row created within
     * the grace window, regardless of id. The commit-order gap on Galera is
     * sub-second, so a small window covers it. The SSE loop de-duplicates by id
     * before echoing, so a re-scanned row is delivered exactly once. Pass
     * $graceCutoff = 0 to disable (strict `id >` semantics).
     *
     * @return list<WidgetEvent>
     */
    public function findStreamEventsSince(string $widgetId, string $sessionId, int $lastEventId, int $now, int $graceCutoff = 0): array
    {
        $qb = $this->createQueryBuilder('e')
            ->where('e.widgetId = :widgetId')
            ->andWhere('e.sessionId = :sessionId')
            ->andWhere('e.type != :typing')
            ->andWhere('e.expires > :now')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('typing', self::OPERATOR_TYPING_TYPE)
            ->setParameter('now', $now)
            ->orderBy('e.id', 'ASC');

        if ($graceCutoff > 0) {
            $qb->andWhere('(e.id > :lastEventId OR e.created >= :graceCutoff)')
                ->setParameter('lastEventId', $lastEventId)
                ->setParameter('graceCutoff', $graceCutoff);
        } else {
            $qb->andWhere('e.id > :lastEventId')
                ->setParameter('lastEventId', $lastEventId);
        }

        /** @var list<WidgetEvent> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * Highest stream-event id currently stored for a session (0 if none).
     *
     * Used to initialize a fresh SSE subscription so it does not replay
     * historical state-changing events (takeover/handback).
     */
    public function maxStreamEventId(string $widgetId, string $sessionId): int
    {
        $max = $this->createQueryBuilder('e')
            ->select('MAX(e.id)')
            ->where('e.widgetId = :widgetId')
            ->andWhere('e.sessionId = :sessionId')
            ->andWhere('e.type != :typing')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('typing', self::OPERATOR_TYPING_TYPE)
            ->getQuery()
            ->getSingleScalarResult();

        return null === $max ? 0 : (int) $max;
    }

    public function findLatestOperatorTyping(string $widgetId, string $sessionId, int $now): ?WidgetEvent
    {
        return $this->createQueryBuilder('e')
            ->where('e.widgetId = :widgetId')
            ->andWhere('e.sessionId = :sessionId')
            ->andWhere('e.type = :typing')
            ->andWhere('e.expires > :now')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('typing', self::OPERATOR_TYPING_TYPE)
            ->setParameter('now', $now)
            ->orderBy('e.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function deleteOperatorTyping(string $widgetId, string $sessionId): void
    {
        $this->createQueryBuilder('e')
            ->delete()
            ->where('e.widgetId = :widgetId')
            ->andWhere('e.sessionId = :sessionId')
            ->andWhere('e.type = :typing')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('sessionId', $sessionId)
            ->setParameter('typing', self::OPERATOR_TYPING_TYPE)
            ->getQuery()
            ->execute();
    }

    /**
     * Housekeeping: drop expired rows. Called opportunistically on write; read
     * queries already filter by expiry, so correctness never depends on this.
     */
    public function deleteExpired(int $now): int
    {
        return (int) $this->createQueryBuilder('e')
            ->delete()
            ->where('e.expires <= :now')
            ->setParameter('now', $now)
            ->getQuery()
            ->execute();
    }
}
