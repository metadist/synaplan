<?php

namespace App\Repository;

use App\Entity\Subscription;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Subscription>
 */
class SubscriptionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Subscription::class);
    }

    /**
     * Find subscription by level
     */
    public function findByLevel(string $level): ?Subscription
    {
        return $this->findOneBy(['level' => $level, 'active' => true]);
    }

    /**
     * Find all active subscriptions
     */
    public function findAllActive(): array
    {
        return $this->findBy(['active' => true], ['priceMonthly' => 'ASC']);
    }
}

