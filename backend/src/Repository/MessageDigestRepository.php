<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\MessageDigest;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\ParameterType;
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
     * One page of the user's active digests, newest source message first.
     *
     * Chat title comes from the same query. It is null when the chat is gone
     * or belongs to someone else; placeholder titles are left for the caller.
     *
     * @return list<array{
     *     id: int,
     *     title: string,
     *     messageId: int,
     *     chatId: int,
     *     channel: string,
     *     sourceDate: int,
     *     created: int,
     *     chatTitle: string|null
     * }>
     */
    public function findActivePage(int $userId, int $limit, int $offset): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    d.BID AS id,
                    d.BTITLE AS title,
                    d.BMESSAGEID AS messageId,
                    d.BCHATID AS chatId,
                    d.BCHANNEL AS channel,
                    d.BSOURCEDATE AS sourceDate,
                    d.BCREATED AS created,
                    c.BTITLE AS chatTitle
                FROM BMESSAGEDIGESTS d
                LEFT JOIN BCHATS c ON c.BID = d.BCHATID AND c.BUSERID = d.BUSERID
                WHERE d.BUSERID = :userId AND d.BACTIVE = 1
                ORDER BY d.BSOURCEDATE DESC, d.BID DESC
                LIMIT :limit OFFSET :offset
                SQL,
            ['userId' => $userId, 'limit' => $limit, 'offset' => $offset],
            [
                'userId' => ParameterType::INTEGER,
                'limit' => ParameterType::INTEGER,
                'offset' => ParameterType::INTEGER,
            ],
        )->fetchAllAssociative();

        return array_map($this->mapPageRow(...), $rows);
    }

    /**
     * Every active digest of one user, in the same order as {@see findActivePage()}.
     *
     * @return list<array{title: string, messageId: int, chatId: int, channel: string, sourceDate: int}>
     */
    public function findActiveForExport(int $userId): array
    {
        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            <<<'SQL'
                SELECT
                    BTITLE AS title,
                    BMESSAGEID AS messageId,
                    BCHATID AS chatId,
                    BCHANNEL AS channel,
                    BSOURCEDATE AS sourceDate
                FROM BMESSAGEDIGESTS
                WHERE BUSERID = :userId AND BACTIVE = 1
                ORDER BY BSOURCEDATE DESC, BID DESC
                SQL,
            ['userId' => $userId],
            ['userId' => ParameterType::INTEGER],
        )->fetchAllAssociative();

        return array_map(static fn (array $row): array => [
            'title' => (string) $row['title'],
            'messageId' => (int) $row['messageId'],
            'chatId' => (int) $row['chatId'],
            'channel' => (string) $row['channel'],
            'sourceDate' => (int) $row['sourceDate'],
        ], $rows);
    }

    /**
     * Active digest id only when this user owns it. Unknown, foreign and
     * already inactive ids all miss, so a caller can 404 without learning which.
     */
    public function findActiveOwnedId(int $userId, int $digestId): ?int
    {
        $stmt = $this->getEntityManager()->getConnection()->prepare(
            'SELECT BID FROM BMESSAGEDIGESTS WHERE BID = :id AND BUSERID = :userId AND BACTIVE = 1',
        );
        $stmt->bindValue('id', $digestId);
        $stmt->bindValue('userId', $userId);
        $value = $stmt->executeQuery()->fetchOne();

        return false === $value || null === $value ? null : (int) $value;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     messageId: int,
     *     chatId: int,
     *     channel: string,
     *     sourceDate: int,
     *     created: int,
     *     chatTitle: string|null
     * }
     */
    private function mapPageRow(array $row): array
    {
        $chatTitle = $row['chatTitle'] ?? null;

        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'messageId' => (int) $row['messageId'],
            'chatId' => (int) $row['chatId'],
            'channel' => (string) $row['channel'],
            'sourceDate' => (int) $row['sourceDate'],
            'created' => (int) $row['created'],
            'chatTitle' => is_string($chatTitle) ? $chatTitle : null,
        ];
    }

    /**
     * Insert or update the row for this (user, message).
     *
     * The only row an update may touch is the one with that pair. A primary-key
     * collision with some other row retries with a fresh id from `$nextId`
     * (at most 5 inserts). A concurrent insert of the same message is updated
     * and its existing BID is returned, so the Qdrant point id stays stable.
     *
     * @param callable(): int $nextId
     */
    public function upsert(MessageDigest $digest, callable $nextId): int
    {
        $existingId = $this->findIdByUserAndMessage($digest->getUserId(), $digest->getMessageId());
        if (null !== $existingId) {
            $this->updateById($existingId, $digest);

            return $existingId;
        }

        $attempts = 5;
        $lastConflict = null;
        for ($attempt = 1; $attempt <= $attempts; ++$attempt) {
            $id = (int) $nextId();
            try {
                $this->insertDigest($id, $digest);

                return $id;
            } catch (UniqueConstraintViolationException $e) {
                $lastConflict = $e;
                $existingId = $this->findIdByUserAndMessage($digest->getUserId(), $digest->getMessageId());
                if (null !== $existingId) {
                    $this->updateById($existingId, $digest);

                    return $existingId;
                }
            }
        }

        throw new MessageDigestIdCollisionException($digest->getUserId(), $digest->getMessageId(), $attempts, $lastConflict);
    }

    /**
     * Active message ids and digest ids for this user among the candidates.
     * One query, so a Qdrant hit whose row is inactive or gone can be dropped
     * before it reaches a prompt.
     *
     * @param list<int> $messageIds
     * @param list<int> $digestIds
     *
     * @return array{message_ids: list<int>, digest_ids: list<int>}
     */
    public function findActiveMatches(int $userId, array $messageIds, array $digestIds): array
    {
        $messageIds = array_values(array_unique(array_map(intval(...), $messageIds)));
        $digestIds = array_values(array_unique(array_map(intval(...), $digestIds)));
        $messageIds = array_values(array_filter($messageIds, static fn (int $id): bool => $id > 0));
        $digestIds = array_values(array_filter($digestIds, static fn (int $id): bool => $id > 0));

        if ([] === $messageIds && [] === $digestIds) {
            return ['message_ids' => [], 'digest_ids' => []];
        }

        $params = ['userId' => $userId];
        $types = [];
        $clauses = [];
        if ([] !== $messageIds) {
            $clauses[] = 'BMESSAGEID IN (:messageIds)';
            $params['messageIds'] = $messageIds;
            $types['messageIds'] = ArrayParameterType::INTEGER;
        }
        if ([] !== $digestIds) {
            $clauses[] = 'BID IN (:digestIds)';
            $params['digestIds'] = $digestIds;
            $types['digestIds'] = ArrayParameterType::INTEGER;
        }

        $rows = $this->getEntityManager()->getConnection()->executeQuery(
            'SELECT BID, BMESSAGEID FROM BMESSAGEDIGESTS WHERE BUSERID = :userId AND BACTIVE = 1 AND ('.implode(' OR ', $clauses).')',
            $params,
            $types,
        )->fetchAllNumeric();

        $matchedMessageIds = [];
        $matchedDigestIds = [];
        foreach ($rows as $row) {
            $matchedDigestIds[] = (int) $row[0];
            $matchedMessageIds[] = (int) $row[1];
        }

        return [
            'message_ids' => array_values(array_unique($matchedMessageIds)),
            'digest_ids' => array_values(array_unique($matchedDigestIds)),
        ];
    }

    /**
     * Digest ids among `$digestIds` that still have a row for this user,
     * whether or not the row is active.
     *
     * @param list<int> $digestIds
     *
     * @return list<int>
     */
    public function findExistingIds(int $userId, array $digestIds): array
    {
        return $this->findExistingColumn($userId, $digestIds, 'BID');
    }

    /**
     * Message ids among `$messageIds` that still have a digest row for this user.
     *
     * @param list<int> $messageIds
     *
     * @return list<int>
     */
    public function findExistingMessageIds(int $userId, array $messageIds): array
    {
        return $this->findExistingColumn($userId, $messageIds, 'BMESSAGEID');
    }

    private function findIdByUserAndMessage(int $userId, int $messageId): ?int
    {
        $stmt = $this->getEntityManager()->getConnection()->prepare(
            'SELECT BID FROM BMESSAGEDIGESTS WHERE BUSERID = :userId AND BMESSAGEID = :messageId',
        );
        $stmt->bindValue('userId', $userId);
        $stmt->bindValue('messageId', $messageId);
        $value = $stmt->executeQuery()->fetchOne();

        return false === $value || null === $value ? null : (int) $value;
    }

    private function updateById(int $id, MessageDigest $digest): void
    {
        $stmt = $this->getEntityManager()->getConnection()->prepare(
            'UPDATE BMESSAGEDIGESTS SET BTITLE = :title, BCHANNEL = :channel, BSOURCEDATE = :sourceDate, BACTIVE = :active WHERE BID = :id',
        );
        $stmt->bindValue('title', $digest->getTitle());
        $stmt->bindValue('channel', $digest->getChannel());
        $stmt->bindValue('sourceDate', $digest->getSourceDate());
        $stmt->bindValue('active', $digest->isActive() ? 1 : 0);
        $stmt->bindValue('id', $id);
        $stmt->executeStatement();
    }

    private function insertDigest(int $id, MessageDigest $digest): void
    {
        $stmt = $this->getEntityManager()->getConnection()->prepare(
            <<<'SQL'
                INSERT INTO BMESSAGEDIGESTS
                    (BID, BUSERID, BCHATID, BMESSAGEID, BTITLE, BCHANNEL, BSOURCEDATE, BACTIVE, BCREATED)
                VALUES
                    (:id, :userId, :chatId, :messageId, :title, :channel, :sourceDate, :active, :created)
                SQL,
        );
        $stmt->bindValue('id', $id);
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

    /**
     * @param list<int> $ids
     *
     * @return list<int>
     */
    private function findExistingColumn(int $userId, array $ids, string $column): array
    {
        if ([] === $ids) {
            return [];
        }
        if (!in_array($column, ['BID', 'BMESSAGEID'], true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported digest column "%s".', $column));
        }

        $found = [];
        foreach (array_chunk(array_values(array_unique(array_map(intval(...), $ids))), 500) as $chunk) {
            $chunk = array_values(array_filter($chunk, static fn (int $id): bool => $id > 0));
            if ([] === $chunk) {
                continue;
            }
            $rows = $this->getEntityManager()->getConnection()->executeQuery(
                sprintf('SELECT %s FROM BMESSAGEDIGESTS WHERE BUSERID = :userId AND %s IN (:ids)', $column, $column),
                ['userId' => $userId, 'ids' => $chunk],
                ['ids' => ArrayParameterType::INTEGER],
            )->fetchFirstColumn();
            foreach ($rows as $row) {
                $found[] = (int) $row;
            }
        }

        return array_values(array_unique($found));
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
     * Keyset page of a user's inactive digests. Reindex deletes their Qdrant
     * points even when the earlier best-effort delete failed.
     *
     * @return list<MessageDigest>
     */
    public function findInactiveForUserAfterId(int $userId, int $afterId, int $limit): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.userId = :userId')
            ->andWhere('d.active = false')
            ->andWhere('d.id > :afterId')
            ->setParameter('userId', $userId)
            ->setParameter('afterId', $afterId)
            ->orderBy('d.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * User ids that have at least one active digest.
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

    /**
     * Every user that still has a digest row. `--all-users` reindex must also
     * see users whose rows are all inactive, so their leftover points are removed.
     *
     * @return list<int>
     */
    public function findDistinctUserIds(): array
    {
        $rows = $this->createQueryBuilder('d')
            ->select('DISTINCT d.userId')
            ->getQuery()
            ->getSingleColumnResult();

        return array_map(intval(...), $rows);
    }
}
