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
     */
    public function countActiveSessionsByWidget(string $widgetId): int
    {
        return $this->createQueryBuilder('ws')
            ->select('COUNT(ws.id)')
            ->where('ws.widgetId = :widgetId')
            ->andWhere('ws.expires > :now')
            ->setParameter('widgetId', $widgetId)
            ->setParameter('now', time())
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get total message count for a widget.
     */
    public function getTotalMessageCountByWidget(string $widgetId): int
    {
        return $this->createQueryBuilder('ws')
            ->select('SUM(ws.messageCount)')
            ->where('ws.widgetId = :widgetId')
            ->setParameter('widgetId', $widgetId)
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
}
