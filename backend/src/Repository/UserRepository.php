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
            ->where("u.paymentDetails LIKE :customerId ESCAPE '!'")
            ->setParameter('customerId', '%"stripe_customer_id":"'.$this->escapeLike($stripeCustomerId).'"%')
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
     * JSON column. Google purchase tokens can contain the SQL LIKE wildcard `_`
     * (and Apple ids could in theory too), so the id is wildcard-escaped before
     * being embedded — otherwise `_`/`%` would match the wrong user and weaken
     * replay protection. We match the quoted key:value pair to avoid accidental
     * substring hits. Powers replay protection (one receipt → one user) and
     * notification → user matching.
     */
    public function findByIapPurchaseId(string $purchaseId): ?User
    {
        $escaped = $this->escapeLike($purchaseId);

        return $this->createQueryBuilder('u')
            ->where("u.paymentDetails LIKE :appleId ESCAPE '!'")
            ->orWhere("u.paymentDetails LIKE :googleToken ESCAPE '!'")
            ->setParameter('appleId', '%"original_transaction_id":"'.$escaped.'"%')
            ->setParameter('googleToken', '%"purchase_token":"'.$escaped.'"%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Find the user linked to a Sign-in-with-Apple identity by its stable `sub`
     * (stored in BUSERDETAILS.apple_sub). Apple's `sub` is the durable key even
     * when the private-relay email changes, so it is the primary match for the
     * Apple flow. Mirrors {@see findByStripeCustomerId()} — a wildcard-escaped
     * LIKE over the JSON column, avoiding a schema migration.
     */
    public function findByAppleSub(string $appleSub): ?User
    {
        return $this->createQueryBuilder('u')
            ->where("u.userDetails LIKE :appleSub ESCAPE '!'")
            ->setParameter('appleSub', '%"apple_sub":"'.$this->escapeLike($appleSub).'"%')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Escape SQL LIKE wildcards in a value that is embedded verbatim into a LIKE
     * pattern, using `!` as the escape character (paired with `ESCAPE '!'`).
     * Without this, `%` and `_` in store-issued ids would act as wildcards.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    public function save(User $user): void
    {
        $this->getEntityManager()->persist($user);
        $this->getEntityManager()->flush();
    }
}
