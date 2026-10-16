<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\ChatSummary;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Durable rolling-summary rows (one per chat) behind the Redis hot cache.
 *
 * @extends ServiceEntityRepository<ChatSummary>
 */
class ChatSummaryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ChatSummary::class);
    }

    public function findOneByChatId(int $chatId): ?ChatSummary
    {
        return $this->findOneBy(['chatId' => $chatId]);
    }

    /**
     * Atomic per-chat upsert. Native SQL (not find-modify-flush) so two
     * workers refreshing the same chat concurrently cannot race into a
     * duplicate-key failure — last write wins, which is correct here because
     * a later refresh always covers a higher (or equal) message id.
     *
     * The row is written only while the chat exists and belongs to the user,
     * checked in the same statement: a refresh that outlives the chat's or
     * the account's deletion writes nothing back.
     *
     * @return bool false when nothing was written
     */
    public function upsert(
        int $chatId,
        int $userId,
        string $summary,
        int $upToMessageId,
        int $summarizedCount,
        string $fingerprint,
    ): bool {
        $sql = <<<'SQL'
            INSERT INTO BCHATSUMMARIES
                (BCHATID, BUSERID, BSUMMARY, BUPTOMESSAGEID, BSUMMARIZEDCOUNT, BFINGERPRINT, BUPDATED)
            SELECT c.BID, c.BUSERID, :summary, :upToMessageId, :summarizedCount, :fingerprint, :updated
            FROM BCHATS c
            WHERE c.BID = :chatId AND c.BUSERID = :userId
            ON DUPLICATE KEY UPDATE
                BUSERID = VALUES(BUSERID),
                BSUMMARY = VALUES(BSUMMARY),
                BUPTOMESSAGEID = VALUES(BUPTOMESSAGEID),
                BSUMMARIZEDCOUNT = VALUES(BSUMMARIZEDCOUNT),
                BFINGERPRINT = VALUES(BFINGERPRINT),
                BUPDATED = VALUES(BUPDATED)
            SQL;

        $stmt = $this->getEntityManager()->getConnection()->prepare($sql);
        $stmt->bindValue('chatId', $chatId);
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('summary', $summary);
        $stmt->bindValue('upToMessageId', $upToMessageId);
        $stmt->bindValue('summarizedCount', $summarizedCount);
        $stmt->bindValue('fingerprint', $fingerprint);
        $stmt->bindValue('updated', time());

        return $stmt->executeStatement() > 0;
    }

    public function deleteByChatId(int $chatId): void
    {
        $stmt = $this->getEntityManager()->getConnection()->prepare(
            'DELETE FROM BCHATSUMMARIES WHERE BCHATID = :chatId',
        );
        $stmt->bindValue('chatId', $chatId);
        $stmt->executeStatement();
    }

    public function deleteByUser(int $userId): void
    {
        $stmt = $this->getEntityManager()->getConnection()->prepare(
            'DELETE FROM BCHATSUMMARIES WHERE BUSERID = :userId',
        );
        $stmt->bindValue('userId', $userId);
        $stmt->executeStatement();
    }
}
