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
            ->getQuery()
            ->getResult();
    }

    public function findRecentByUser(int $userId, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('m.unixTimestamp', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find conversation thread for a message (based on TrackId and time window)
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
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Find conversation history for context
     */
    public function findConversationHistory(int $userId, string $trackingId, int $limit = 10): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.userId = :userId')
            ->andWhere('m.trackingId = :trackingId')
            ->setParameter('userId', $userId)
            ->setParameter('trackingId', $trackingId)
            ->orderBy('m.unixTimestamp', 'ASC') // Oldest first for context
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Save message
     */
    public function save(Message $message, bool $flush = true): void
    {
        $this->getEntityManager()->persist($message);
        
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}

