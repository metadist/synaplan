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
        // Use native SQL because Doctrine DQL doesn't support UNIX_TIMESTAMP and STR_TO_DATE
        $sql = 'SELECT * FROM BINBOUNDEMAILHANDLER
                WHERE BSTATUS = :status
                AND (BLASTCHECKED IS NULL
                     OR (UNIX_TIMESTAMP(STR_TO_DATE(BLASTCHECKED, "%Y%m%d%H%i%s")) + (BCHECKINTERVAL * 60)) <= UNIX_TIMESTAMP())';

        $conn = $this->getEntityManager()->getConnection();
        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery(['status' => 'active']);

        $handlers = [];
        foreach ($result->fetchAllAssociative() as $row) {
            // Convert database row to entity
            $handler = $this->find($row['BID']);
            if ($handler) {
                $handlers[] = $handler;
            }
        }

        return $handlers;
    }
}
