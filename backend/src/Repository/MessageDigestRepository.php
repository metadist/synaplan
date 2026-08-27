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
}
