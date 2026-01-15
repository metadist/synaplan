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
     * Save message.
     */
    public function save(Message $message, bool $flush = true): void
    {
        $this->getEntityManager()->persist($message);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
