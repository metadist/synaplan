<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    public function findByEmail(string $email): ?User
    {
        return $this->findOneBy(['mail' => $email]);
    }

    public function findByProviderId(string $providerId): ?User
    {
        return $this->findOneBy(['providerId' => $providerId]);
    }

    /**
     * Find user by Stripe customer ID (searches in paymentDetails JSON).
     */
    public function findByStripeCustomerId(string $stripeCustomerId): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.paymentDetails LIKE :customerId')
            ->setParameter('customerId', '%"stripe_customer_id":"'.$stripeCustomerId.'"%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
