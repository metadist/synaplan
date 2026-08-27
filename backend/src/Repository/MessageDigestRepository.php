<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MessageDigest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Authoritative MariaDB store for message digests (one row per key message).
 *
 * @extends ServiceEntityRepository<MessageDigest>
 */
class MessageDigestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, MessageDigest::class);
    }

    public function findOneByUserAndMessage(int $userId, int $messageId): ?MessageDigest
    {
        return $this->findOneBy(['userId' => $userId, 'messageId' => $messageId]);
    }

    /**
     * Highest source message id already digested for a user — one half of the
     * per-user watermark (the other half is the BCONFIG cursor, which also
     * advances over batches that yielded no digest-worthy message).
     */
    public function maxMessageIdForUser(int $userId): int
    {
        $result = $this->createQueryBuilder('d')
            ->select('MAX(d.messageId)')
            ->where('d.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result;
    }

    /**
     * Message ids (of the given candidates) that already have a digest —
     * used to drop already-digested messages before they reach the model.
     *
     * @param list<int> $messageIds
     *
     * @return list<int>
     */
    public function findDigestedMessageIds(int $userId, array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT BMESSAGEID FROM BMESSAGEDIGESTS WHERE BUSERID = :userId AND BMESSAGEID IN (:messageIds)',
            ['userId' => $userId, 'messageIds' => $messageIds],
            ['messageIds' => ArrayParameterType::INTEGER],
        )->fetchFirstColumn();

        return array_map(intval(...), $rows);
    }

    /**
     * Existing digest titles for a set of chats — dedup context handed to the
     * digest model so it does not create near-duplicate titles for follow-up
     * messages in the same thread.
     *
     * @param list<int> $chatIds
     *
     * @return list<string>
     */
    public function findTitlesForChats(int $userId, array $chatIds, int $limit = 50): array
    {
        if ([] === $chatIds) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->select('d.title')
            ->where('d.userId = :userId')
            ->andWhere('d.chatId IN (:chatIds)')
            ->andWhere('d.active = true')
            ->setParameter('userId', $userId)
            ->setParameter('chatIds', $chatIds)
            ->orderBy('d.sourceDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getSingleColumnResult();
    }

    /**
     * Active digests for a set of message ids, scoped to one user — resolves
     * `[Message:ID]` badge references in the web UI after a page reload.
     *
     * @param list<int> $messageIds
     *
     * @return list<MessageDigest>
     */
    public function findActiveByUserAndMessageIds(int $userId, array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.messageId IN (:messageIds)')
            ->andWhere('d.active = true')
            ->setParameter('userId', $userId)
            ->setParameter('messageIds', $messageIds)
            ->orderBy('d.messageId', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Atomic per-(user, message) upsert. Native SQL so a concurrent run and a
     * backfill hitting the same message cannot race into a duplicate-key
     * failure; the existing BID is preserved so the Qdrant point id stays
     * stable across title rewrites.
     */
    public function upsert(MessageDigest $digest): void
    {
        $sql = <<<'SQL'
            INSERT INTO BMESSAGEDIGESTS
                (BID, BUSERID, BCHATID, BMESSAGEID, BTITLE, BCHANNEL, BSOURCEDATE, BACTIVE, BCREATED)
            VALUES
                (:id, :userId, :chatId, :messageId, :title, :channel, :sourceDate, :active, :created)
            ON DUPLICATE KEY UPDATE
                BTITLE = VALUES(BTITLE),
                BCHANNEL = VALUES(BCHANNEL),
                BSOURCEDATE = VALUES(BSOURCEDATE),
                BACTIVE = VALUES(BACTIVE)
            SQL;

        $stmt = $this->getEntityManager()->getConnection()->prepare($sql);
        $stmt->bindValue('id', $digest->getId());
        $stmt->bindValue('userId', $digest->getUserId());
        $stmt->bindValue('chatId', $digest->getChatId());
        $stmt->bindValue('messageId', $digest->getMessageId());
        $stmt->bindValue('title', $digest->getTitle());
        $stmt->bindValue('channel', $digest->getChannel());
        $stmt->bindValue('sourceDate', $digest->getSourceDate());
        $stmt->bindValue('active', $digest->isActive() ? 1 : 0);
        $stmt->bindValue('created', $digest->getCreated());
        $stmt->executeStatement();
    }

    public function deleteAllForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->delete()
            ->where('d.userId = :userId')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->execute();
    }

    public function countActiveForUser(int $userId): int
    {
        return (int) $this->createQueryBuilder('d')
            ->select('COUNT(d.id)')
            ->where('d.userId = :userId')
            ->andWhere('d.active = true')
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Oldest active digests by source date — the prune candidates when a user
     * is over the per-user cap.
     *
     * @return list<MessageDigest>
     */
    public function findOldestActive(int $userId, int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.active = true')
            ->setParameter('userId', $userId)
            ->orderBy('d.sourceDate', 'ASC')
            ->addOrderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * All active digests of one chat — deletion hygiene when the chat goes away.
     *
     * @return list<MessageDigest>
     */
    public function findActiveByChat(int $userId, int $chatId): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.chatId = :chatId')
            ->andWhere('d.active = true')
            ->setParameter('userId', $userId)
            ->setParameter('chatId', $chatId)
            ->getQuery()
            ->getResult();
    }

    /**
     * Soft-delete a set of digests. Bulk DQL, so any already-hydrated
     * entities are NOT synchronized — callers work on fresh reads.
     *
     * @param list<int> $digestIds
     */
    public function deactivateByIds(array $digestIds): int
    {
        if ([] === $digestIds) {
            return 0;
        }

        return (int) $this->createQueryBuilder('d')
            ->update()
            ->set('d.active', 'false')
            ->where('d.id IN (:ids)')
            ->setParameter('ids', $digestIds)
            ->getQuery()
            ->execute();
    }

    /**
     * Keyset page of a user's active digests (ordered by id) — lets the
     * re-index command walk an arbitrarily large table without OFFSET scans
     * or holding everything in memory.
     *
     * @return list<MessageDigest>
     */
    public function findActiveForUserAfterId(int $userId, int $afterId, int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.active = true')
            ->andWhere('d.id > :afterId')
            ->setParameter('userId', $userId)
            ->setParameter('afterId', $afterId)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * User ids that have at least one active digest — enumeration base for
     * the re-index command.
     *
     * @return list<int>
     */
    public function findDistinctActiveUserIds(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.userId')
            ->where('d.active = true')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $rows);
    }
}
