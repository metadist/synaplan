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

    /**
     * MOBILE-APP SEAM (Epic 5.4): find the user who owns an IAP purchase, by
     * its stable per-subscription id (Apple `original_transaction_id` or Google
     * `purchase_token`) stored inside `BPAYMENTDETAILS.subscription`.
     *
     * Migration-free, mirroring {@see findByStripeCustomerId()}: a LIKE over the
     * JSON column. The id is store-issued (digits / URL-safe token), so it
     * cannot inject JSON-structural characters; we still match the quoted
     * key:value pair to avoid accidental substring hits. Powers replay
     * protection (one receipt → one user) and notification → user matching.
     */
    public function findByIapPurchaseId(string $purchaseId): ?User
    {
        return $this->createQueryBuilder('u')
            ->where('u.paymentDetails LIKE :appleId')
            ->orWhere('u.paymentDetails LIKE :googleToken')
            ->setParameter('appleId', '%"original_transaction_id":"'.$purchaseId.'"%')
            ->setParameter('googleToken', '%"purchase_token":"'.$purchaseId.'"%')
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
