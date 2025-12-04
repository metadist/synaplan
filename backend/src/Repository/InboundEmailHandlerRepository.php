<?php

namespace App\Repository;

use App\Entity\InboundEmailHandler;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class InboundEmailHandlerRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, InboundEmailHandler::class);
    }

    /**
     * Find all handlers for a user.
     */
    public function findByUser(int $userId): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.userId = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('h.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find active handlers for a user.
     */
    public function findActiveByUser(int $userId): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.userId = :userId')
            ->andWhere('h.status = :status')
            ->setParameter('userId', $userId)
            ->setParameter('status', 'active')
            ->orderBy('h.created', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find handler by ID and user (security check).
     */
    public function findByIdAndUser(int $id, int $userId): ?InboundEmailHandler
    {
        return $this->createQueryBuilder('h')
            ->where('h.id = :id')
            ->andWhere('h.userId = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find all active handlers (for background processing).
     */
    public function findAllActive(): array
    {
        return $this->createQueryBuilder('h')
            ->where('h.status = :status')
            ->setParameter('status', 'active')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find handlers that need to be checked (based on checkInterval).
     */
    public function findHandlersToCheck(): array
    {
        $now = time();

        return $this->createQueryBuilder('h')
            ->where('h.status = :status')
            ->setParameter('status', 'active')
            ->andWhere('(h.lastChecked IS NULL OR (UNIX_TIMESTAMP(STR_TO_DATE(h.lastChecked, :format)) + (h.checkInterval * 60)) <= :now)')
            ->setParameter('format', '%Y%m%d%H%i%s')
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
