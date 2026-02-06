<?php

namespace App\Repository;

use App\Entity\WidgetSession;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WidgetSession>
 */
class WidgetSessionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WidgetSession::class);
    }

    public function save(WidgetSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->persist($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(WidgetSession $session, bool $flush = false): void
    {
        $this->getEntityManager()->remove($session);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Find session by widget ID and session ID.
     */
    public function findByWidgetAndSession(string $widgetId, string $sessionId): ?WidgetSession
    {
        return $this->findOneBy([
            'widgetId' => $widgetId,
            'sessionId' => $sessionId,
        ]);
    }

    /**
     * Delete expired sessions.
     */
    public function deleteExpiredSessions(): int
    {
        return $this->createQueryBuilder('ws')
            ->delete()
            ->where('ws.expires < :now')
            ->setParameter('now', time())
            ->getQuery()
            ->execute();
    }

    /**
     * Count active sessions for a widget.
     *
     * A session is considered "active" if it had activity in the last 5 minutes.
     * Test sessions (session ID starting with 'test_') are excluded.
     */
    public function countActiveSessionsByWidget(string $widgetId): int
    {
        // Active = last message within the last 5 minutes
        $activeThreshold = time() - (5 * 60);

        return $this->createQueryBuilder('ws')
            ->select('COUNT(ws.id)')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.lastMessage > :threshold')
            ->andWhere('ws.sessionId NOT LIKE :testPrefix')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('threshold', $activeThreshold)
            ->setParameter('testPrefix', 'test_%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get total message count for a widget.
     * Test sessions (session ID starting with 'test_') are excluded.
     */
    public function getTotalMessageCountByWidget(string $widgetId): int
    {
        return $this->createQueryBuilder('ws')
            ->select('SUM(ws.messageCount)')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.sessionId NOT LIKE :testPrefix')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('testPrefix', 'test_%')
            ->getQuery()
            ->getSingleScalarResult() ?? 0;
    }

    /**
     * Fetch widget sessions mapped to the provided chat IDs.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findSessionsByChatIds(array $chatIds): array
    {
        if (empty($chatIds)) {
            return [];
        }

        $conn = $this->getEntityManager()->getConnection();

        $sql = '
            SELECT
                ws.BCHATID AS chat_id,
                ws.BWIDGETID AS widget_id,
                ws.BSESSIONID AS session_id,
                ws.BMESSAGECOUNT AS message_count,
                ws.BFILECOUNT AS file_count,
                ws.BLASTMESSAGE AS last_message,
                ws.BCREATED AS created,
                ws.BEXPIRES AS expires,
                w.BNAME AS widget_name
            FROM BWIDGET_SESSIONS ws
            LEFT JOIN BWIDGETS w ON w.BWIDGETID = ws.BWIDGETID
            WHERE ws.BCHATID IN (:chat_ids)
        ';

        return $conn->executeQuery(
            $sql,
            ['chat_ids' => $chatIds],
            ['chat_ids' => ArrayParameterType::INTEGER]
        )->fetchAllAssociative();
    }

    /**
     * Find sessions for a widget with pagination and filtering.
     *
     * @param array{
     *     status?: string,
     *     mode?: string,
     *     from?: int,
     *     to?: int,
     *     sort?: string,
     *     order?: string,
     *     favorite?: bool,
     *     sessionIds?: array<string>
     * } $filters
     *
     * @return array{sessions: WidgetSession[], total: int}
     */
    public function findSessionsByWidget(
        string $widgetId,
        int $limit = 20,
        int $offset = 0,
        array $filters = [],
    ): array {
        $qb = $this->createQueryBuilder('ws')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.sessionId NOT LIKE :testPrefix')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('testPrefix', 'test_%');

        // Filter by status (active/expired)
        if (isset($filters['status'])) {
            $now = time();
            if ('active' === $filters['status']) {
                $qb->andWhere('ws.expires > :now')
                    ->setParameter('now', $now);
            } elseif ('expired' === $filters['status']) {
                $qb->andWhere('ws.expires <= :now')
                    ->setParameter('now', $now);
            }
        }

        // Filter by mode (ai/human/waiting)
        if (isset($filters['mode']) && in_array($filters['mode'], ['ai', 'human', 'waiting'], true)) {
            $qb->andWhere('ws.mode = :mode')
                ->setParameter('mode', $filters['mode']);
        }

        // Filter by date range
        if (isset($filters['from'])) {
            $qb->andWhere('ws.created >= :from')
                ->setParameter('from', $filters['from']);
        }
        if (isset($filters['to'])) {
            $qb->andWhere('ws.created <= :to')
                ->setParameter('to', $filters['to']);
        }

        // Filter by favorite status
        if (isset($filters['favorite'])) {
            $qb->andWhere('ws.isFavorite = :isFavorite')
                ->setParameter('isFavorite', $filters['favorite']);
        }

        // Filter by specific session IDs
        if (isset($filters['sessionIds']) && count($filters['sessionIds']) > 0) {
            $qb->andWhere('ws.sessionId IN (:sessionIds)')
                ->setParameter('sessionIds', $filters['sessionIds']);
        }

        // Get total count
        $countQb = clone $qb;
        $total = (int) $countQb->select('COUNT(ws.id)')->getQuery()->getSingleScalarResult();

        // Apply sorting
        $sortField = match ($filters['sort'] ?? 'lastMessage') {
            'created' => 'ws.created',
            'messageCount' => 'ws.messageCount',
            'lastMessage' => 'ws.lastMessage',
            default => 'ws.lastMessage',
        };
        $sortOrder = ($filters['order'] ?? 'DESC') === 'ASC' ? 'ASC' : 'DESC';
        $qb->orderBy($sortField, $sortOrder);

        // Apply pagination
        $qb->setMaxResults($limit)
            ->setFirstResult($offset);

        $sessions = $qb->getQuery()->getResult();

        return [
            'sessions' => $sessions,
            'total' => $total,
        ];
    }

    /**
     * Find sessions waiting for human response for a widget.
     *
     * @return WidgetSession[]
     */
    public function findWaitingForHuman(string $widgetId): array
    {
        return $this->createQueryBuilder('ws')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.mode = :mode')
            ->andWhere('ws.sessionId NOT LIKE :testPrefix')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('mode', WidgetSession::MODE_WAITING)
            ->setParameter('testPrefix', 'test_%')
            ->orderBy('ws.lastMessage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active human sessions for an operator.
     *
     * @return WidgetSession[]
     */
    public function findActiveHumanSessionsByOperator(int $operatorId): array
    {
        $now = time();

        return $this->createQueryBuilder('ws')
            ->where('ws.humanOperatorId = :operatorId')
            ->andWhere('ws.mode = :mode')
            ->andWhere('ws.expires > :now')
            ->setParameter('operatorId', $operatorId)
            ->setParameter('mode', WidgetSession::MODE_HUMAN)
            ->setParameter('now', $now)
            ->orderBy('ws.lastMessage', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count sessions by mode for a widget.
     *
     * @return array{ai: int, human: int, waiting: int}
     */
    public function countSessionsByMode(string $widgetId): array
    {
        $now = time();

        $result = $this->createQueryBuilder('ws')
            ->select('ws.mode, COUNT(ws.id) as count')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.expires > :now')
            ->andWhere('ws.sessionId NOT LIKE :testPrefix')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('now', $now)
            ->setParameter('testPrefix', 'test_%')
            ->groupBy('ws.mode')
            ->getQuery()
            ->getResult();

        $counts = ['ai' => 0, 'human' => 0, 'waiting' => 0];
        foreach ($result as $row) {
            $counts[$row['mode']] = (int) $row['count'];
        }

        return $counts;
    }

    /**
     * Delete sessions by their session IDs.
     *
     * Returns the sessions that were found for deletion (caller must handle chat/message cleanup).
     *
     * @param string[] $sessionIds
     *
     * @return WidgetSession[] Sessions that were found
     */
    public function findBySessionIds(string $widgetId, array $sessionIds): array
    {
        if (empty($sessionIds)) {
            return [];
        }

        return $this->createQueryBuilder('ws')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.sessionId IN (:sessionIds)')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('sessionIds', $sessionIds)
            ->getQuery()
            ->getResult();
    }
}
