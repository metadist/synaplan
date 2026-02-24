<?php

namespace App\Repository;

use App\Entity\Message;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class MessageRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Message::class);
    }

    public function findByTrackingId(int $trackingId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.trackingId = :trackingId')
            ->setParameter('trackingId', $trackingId)
            ->orderBy('m.unixTimestamp', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findRecentByUser(int $userId, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('m.unixTimestamp', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find conversation thread for a message (based on TrackId and time window).
     */
    public function findThread(Message $message, int $limit = 20, int $timeWindowSeconds = 1200): array
    {
        $cutoffTime = $message->getUnixTimestamp() - $timeWindowSeconds;

        return $this->createQueryBuilder('m')
            ->where('m.trackId = :trackId')
            ->andWhere('m.unixTimestamp >= :cutoff')
            ->andWhere('m.unixTimestamp < :currentTime')
            ->andWhere('m.id != :currentId')
            ->setParameter('trackId', $message->getTrackId())
            ->setParameter('cutoff', $cutoffTime)
            ->setParameter('currentTime', $message->getUnixTimestamp())
            ->setParameter('currentId', $message->getId())
            ->orderBy('m.unixTimestamp', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find conversation history for context (legacy - uses trackingId).
     *
     * Used as fallback when chatId is not available (backward compatibility).
     * For new code with chatId, use findChatHistory() instead.
     */
    public function findConversationHistory(int $userId, string $trackingId, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.trackingId = :trackingId')
            ->setParameter('userId', $userId)
            ->setParameter('trackingId', $trackingId)
            ->orderBy('m.unixTimestamp', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find chat history from a specific chat window with intelligent limit.
     *
     * Retrieves the most recent messages from a chat, with adaptive limit
     * based on message length to optimize context window usage.
     *
     * @param int $userId        User ID to filter by
     * @param int $chatId        Chat ID to get messages from
     * @param int $maxMessages   Maximum number of messages (default: 30)
     * @param int $maxTotalChars Maximum total characters across all messages (default: 15000)
     *
     * @return array Array of Message entities, ordered oldest first
     */
    public function findChatHistory(
        int $userId,
        int $chatId,
        int $maxMessages = 30,
        int $maxTotalChars = 15000,
    ): array {
        // Get recent messages from this chat
        // Order by timestamp DESC, then by id DESC to ensure correct order when timestamps are equal
        $messages = $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.chatId = :chatId')
            ->setParameter('userId', $userId)
            ->setParameter('chatId', $chatId)
            ->orderBy('m.unixTimestamp', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($maxMessages)
            ->getQuery()
            ->getResult();

        // Apply character limit: keep newest messages that fit within total char limit
        $result = [];
        $totalChars = 0;

        foreach ($messages as $message) {
            $messageLength = strlen($message->getText());
            if ($message->getFileText()) {
                $messageLength += strlen($message->getFileText());
            }

            // Stop if adding this message would exceed char limit
            // (but always include at least 1 message)
            if (count($result) > 0 && ($totalChars + $messageLength) > $maxTotalChars) {
                break;
            }

            $result[] = $message;
            $totalChars += $messageLength;
        }

        // Reverse to get oldest first (for proper conversation order)
        return array_reverse($result);
    }

    /**
     * Find all messages for a chat, ordered chronologically. No character or count limits.
     * Used for summary analysis where we need the complete conversation.
     *
     * @return Message[]
     */
    public function findAllByChatId(int $userId, int $chatId): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.chatId = :chatId')
            ->setParameter('userId', $userId)
            ->setParameter('chatId', $chatId)
            ->orderBy('m.unixTimestamp', 'ASC')
            ->addOrderBy('m.id', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Save message.
     */
    public function save(Message $message, bool $flush = true): void
    {
        $this->getEntityManager()->persist($message);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Check if a file is attached to any message in a specific chat.
     *
     * Used for security validation in widget file downloads.
     */
    public function isFileInChat(int $chatId, int $fileId): bool
    {
        $result = $this->createQueryBuilder('m')
            ->select('COUNT(m.id)')
            ->innerJoin('m.files', 'f')
            ->where('m.chatId = :chatId')
            ->andWhere('f.id = :fileId')
            ->setParameter('chatId', $chatId)
            ->setParameter('fileId', $fileId)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $result > 0;
    }

    /**
     * Get the last message for each of the given chat IDs.
     * Returns an array keyed by chatId with the message text.
     *
     * @param int[] $chatIds
     *
     * @return array<int, string>
     */
    public function getLastMessageTextForChats(array $chatIds): array
    {
        if (empty($chatIds)) {
            return [];
        }

        // Use a subquery to get the max timestamp per chat, then join to get the message
        $conn = $this->getEntityManager()->getConnection();

        // Use MAX(BID) to get the actual last message (BID is auto-increment)
        $sql = '
            SELECT m.BCHATID as chat_id, m.BTEXT as text
            FROM BMESSAGES m
            INNER JOIN (
                SELECT BCHATID, MAX(BID) as max_id
                FROM BMESSAGES
                WHERE BCHATID IN (?)
                GROUP BY BCHATID
            ) latest ON m.BCHATID = latest.BCHATID AND m.BID = latest.max_id
            WHERE m.BCHATID IN (?)
        ';

        $result = $conn->executeQuery(
            $sql,
            [$chatIds, $chatIds],
            [\Doctrine\DBAL\ArrayParameterType::INTEGER, \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $messages = [];
        foreach ($result->fetchAllAssociative() as $row) {
            $messages[(int) $row['chat_id']] = (string) $row['text'];
        }

        return $messages;
    }

    /**
     * Delete all messages for the given chat IDs.
     *
     * @param array<int> $chatIds
     *
     * @return int Number of deleted messages
     */
    public function deleteByChatIds(array $chatIds): int
    {
        if (empty($chatIds)) {
            return 0;
        }

        $qb = $this->getEntityManager()->createQueryBuilder();

        return $qb->delete(Message::class, 'm')
            ->where($qb->expr()->in('m.chatId', ':chatIds'))
            ->setParameter('chatIds', $chatIds)
            ->getQuery()
            ->execute();
    }
}
