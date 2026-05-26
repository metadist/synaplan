<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\RoutingFeedback;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<RoutingFeedback>
 */
class RoutingFeedbackRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, RoutingFeedback::class);
    }

    /**
     * Get all verified feedbacks for training data export.
     *
     * @return RoutingFeedback[]
     */
    public function findVerified(): array
    {
        return $this->createQueryBuilder('f')
            ->where('f.status = :status')
            ->setParameter('status', RoutingFeedback::STATUS_VERIFIED)
            ->orderBy('f.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count feedbacks submitted by a user within the last N seconds.
     */
    public function countRecentByUser(int $userId, int $windowSeconds = 60): int
    {
        $since = new \DateTimeImmutable("-{$windowSeconds} seconds");

        return (int) $this->createQueryBuilder('f')
            ->select('COUNT(f.id)')
            ->where('f.userId = :userId')
            ->andWhere('f.createdAt >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
