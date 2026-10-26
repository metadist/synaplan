<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\VerificationTokenRepository;
use App\Service\Billing\StripeEntitlement;
use App\Service\Iam\UserLifecycleService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Stripe\StripeObject;
use Symfony\Component\Lock\LockFactory;

/**
 * Turns a paid pay-first Checkout session into an account (PF2).
 *
 * The one creation path for both the Stripe webhook and `/welcome`. Idempotent
 * per session: the account carries the Stripe customer and the session id, a
 * second call finds it, and a cluster-wide lock per session keeps the webhook
 * and the browser from creating it twice. An address that already has an
 * account gets the plan on that account instead (D5).
 */
final readonly class PaidAccountCreator
{
    public const SESSION_KEY = 'pay_first_session';
    public const PASSWORD_LINK_TTL_SECONDS = 7 * 86400;
    private const LOCK_TTL_SECONDS = 60.0;
    /** Stripe subscription states that can no longer charge anyone. */
    private const ENDED_STATUSES = ['canceled', 'incomplete_expired', 'expired', 'revoked', 'refunded'];

    public function __construct(
        private CheckoutSessionGateway $gateway,
        private UserRepository $users,
        private UserLifecycleService $lifecycle,
        private StripeEntitlement $entitlement,
        private VerificationTokenRepository $tokens,
        private PaidAccountMailer $mailer,
        private EntityManagerInterface $em,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The webhook's entry: null when the session is not a pay-first one.
     *
     * @throws PayFirstException when Stripe cannot be reached
     */
    public function settleSession(string $sessionId): ?PaidAccountOutcome
    {
        $checkout = $this->gateway->retrieve($sessionId);

        return null === $checkout ? null : $this->settle($checkout);
    }

    /**
     * @throws PayFirstException when Stripe cannot be reached
     */
    public function settle(PaidCheckout $checkout): PaidAccountOutcome
    {
        if (!$checkout->isSettled()) {
            return PaidAccountOutcome::pending($checkout->plan);
        }

        // Per address, not per session: two purchases for one address must
        // not both pass the existing-account checks.
        $lock = $this->lockFactory->createLock(self::lockKey($checkout->email), self::LOCK_TTL_SECONDS);
        $lock->acquire(true);
        try {
            $byCustomer = $this->users->findByStripeCustomerId((string) $checkout->customerId);
            if ($byCustomer instanceof User) {
                return self::madeFrom($byCustomer, $checkout)
                    ? PaidAccountOutcome::created($byCustomer, $checkout->plan)
                    : PaidAccountOutcome::existing($byCustomer, $checkout->plan);
            }

            // Read Stripe before writing anything, so a Stripe failure leaves
            // no half-made account that a retry would mistake for an old one.
            $subscription = $this->gateway->subscription((string) $checkout->subscriptionId);
            $invoice = $this->entitlement->latestInvoice($subscription);
            $purchase = fn (User $user, bool $newAccount) => $this->recordPurchase($user, $checkout, $newAccount, $subscription, $invoice);

            $existing = $this->users->findOtherByNormalizedEmail($checkout->email, 0);

            return $existing instanceof User ? $this->attach($existing, $checkout, $purchase) : $this->create($checkout, $purchase);
        } finally {
            $lock->release();
        }
    }

    public static function lockKey(string $email): string
    {
        return 'pay-first-account-'.hash('sha256', mb_strtolower(trim($email)));
    }

    public static function madeFrom(User $user, PaidCheckout $checkout): bool
    {
        return ($user->getPaymentDetails()[self::SESSION_KEY] ?? null) === $checkout->sessionId;
    }

    /**
     * @param \Closure(User, bool): void $purchase
     */
    private function create(PaidCheckout $checkout, \Closure $purchase): PaidAccountOutcome
    {
        // The account, its purchase and its set-password token land together
        // or not at all; a half-made account would read as an old one on retry.
        [$user, $token] = $this->em->wrapInTransaction(function () use ($checkout, $purchase): array {
            $user = $this->lifecycle->createUser($checkout->email, bin2hex(random_bytes(32)));
            $purchase($user, true);

            return [$user, $this->tokens->createToken($user, 'password_reset', self::PASSWORD_LINK_TTL_SECONDS)];
        });
        $this->mailer->accountReady($checkout->email, $checkout->plan, $token->getToken(), $user->getLocale());
        $this->logger->info('Pay-first account created', ['user_id' => $user->getId(), 'session_id' => $checkout->sessionId, 'plan' => $checkout->plan]);

        return PaidAccountOutcome::created($user, $checkout->plan);
    }

    /**
     * @param \Closure(User, bool): void $purchase
     */
    private function attach(User $user, PaidCheckout $checkout, \Closure $purchase): PaidAccountOutcome
    {
        if (self::hasOtherLiveSubscription($user, $checkout)) {
            // Two live subscriptions for one person: change nothing and let
            // an operator refund the new one rather than guess which to keep.
            $this->logger->critical('Pay-first payment for an address whose account already has a live subscription; refund it in Stripe', [
                'user_id' => $user->getId(),
                'session_id' => $checkout->sessionId,
                'subscription_id' => $checkout->subscriptionId,
            ]);

            return PaidAccountOutcome::conflict($user, $checkout->plan);
        }

        $purchase($user, false);
        $this->mailer->planOnExistingAccount($checkout->email, $checkout->plan, $user->getLocale());
        $this->logger->info('Pay-first plan added to an existing account', ['user_id' => $user->getId(), 'session_id' => $checkout->sessionId]);

        return PaidAccountOutcome::existing($user, $checkout->plan);
    }

    /**
     * Any subscription on record that Stripe or a store may still charge —
     * not only the ones that grant access today (trialing, past_due, …).
     */
    private static function hasOtherLiveSubscription(User $user, PaidCheckout $checkout): bool
    {
        $sub = $user->getSubscriptionData();
        $id = $sub['stripe_subscription_id'] ?? null;
        if (null !== $id && $id === $checkout->subscriptionId) {
            return false;
        }
        $status = is_string($sub['status'] ?? null) ? $sub['status'] : null;

        return null !== $status && !in_array($status, self::ENDED_STATUSES, true);
    }

    private function recordPurchase(User $user, PaidCheckout $checkout, bool $newAccount, StripeObject $subscription, ?StripeObject $invoice): void
    {
        $details = $user->getPaymentDetails();
        $details['stripe_customer_id'] = $checkout->customerId;
        if ($newAccount) {
            $details[self::SESSION_KEY] = $checkout->sessionId;
        }
        $user->setPaymentDetails($details);

        $this->entitlement->apply($user, $subscription, $invoice);
        $this->em->flush();
    }
}
