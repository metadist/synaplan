<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Topup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Topup>
 */
class TopupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Topup::class);
    }

    /**
     * Sum of completed top-ups for a user within a billing period (inclusive).
     * This is the amount added on top of the tier's monthly cost budget.
     */
    public function sumForUserInPeriod(int $userId, int $periodStart, int $periodEnd): float
    {
        $sum = $this->createQueryBuilder('t')
            ->select('COALESCE(SUM(t.amount), 0)')
            ->where('t.userId = :uid')
            ->andWhere('t.status = :status')
            ->andWhere('t.created >= :start')
            ->andWhere('t.created <= :end')
            ->setParameter('uid', $userId)
            ->setParameter('status', 'completed')
            ->setParameter('start', $periodStart)
            ->setParameter('end', $periodEnd)
            ->getQuery()
            ->getSingleScalarResult();

        return (float) $sum;
    }

    /**
     * Idempotency guard: has a top-up already been recorded for this Stripe
     * checkout session? Prevents double-crediting on webhook retries.
     */
    public function existsForSession(string $stripeSessionId): bool
    {
        $count = (int) $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.stripeSessionId = :sid')
            ->setParameter('sid', $stripeSessionId)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }

    public function save(Topup $topup, bool $flush = true): void
    {
        $this->getEntityManager()->persist($topup);
        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
