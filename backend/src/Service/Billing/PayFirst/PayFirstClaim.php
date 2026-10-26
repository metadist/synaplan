<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * `/welcome` asks for the account a paid session made (PF2, D3).
 *
 * Creates it through {@see PaidAccountCreator} if the webhook is late. Signs
 * in only the browser that started the checkout — the one holding the nonce
 * whose hash is in the session — and only once, for an account made from
 * this session. Stripe does not prove the buyer owns the address, so an
 * existing account is never signed into.
 */
final readonly class PayFirstClaim
{
    public const SIGNED_IN_KEY = 'pay_first_signed_in';

    public function __construct(
        private CheckoutSessionGateway $gateway,
        private PaidAccountCreator $creator,
        private EntityManagerInterface $em,
        private LockFactory $lockFactory,
    ) {
    }

    /**
     * @throws PayFirstException when Stripe cannot be reached
     */
    public function claim(string $sessionId, ?string $nonce): PayFirstClaimResult
    {
        $checkout = '' !== $sessionId ? $this->gateway->retrieve($sessionId) : null;
        if (null === $checkout) {
            return new PayFirstClaimResult(PayFirstClaimResult::NOT_FOUND);
        }

        $outcome = $this->creator->settle($checkout);
        $status = match ($outcome->kind) {
            PaidAccountOutcome::PENDING => PayFirstClaimResult::PENDING,
            PaidAccountOutcome::EXISTING => PayFirstClaimResult::EXISTING,
            PaidAccountOutcome::CONFLICT => PayFirstClaimResult::CONFLICT,
            default => PayFirstClaimResult::READY,
        };
        $signIn = PayFirstClaimResult::READY === $status && $outcome->user instanceof User && $this->maySignIn($outcome->user, $checkout, $nonce)
            ? $outcome->user
            : null;

        return new PayFirstClaimResult($status, $checkout->plan, $checkout->email, $signIn);
    }

    private function maySignIn(User $user, PaidCheckout $checkout, ?string $nonce): bool
    {
        if (null === $nonce || '' === $nonce || '' === $checkout->startNonceHash) {
            return false;
        }
        if (!hash_equals($checkout->startNonceHash, hash('sha256', $nonce)) || !PaidAccountCreator::madeFrom($user, $checkout)) {
            return false;
        }

        // Two claims with the same nonce may arrive together; the grant is
        // decided on a fresh read under a lock, so only the first one wins.
        $lock = $this->lockFactory->createLock('pay-first-sign-in-'.$checkout->sessionId, 30.0);
        $lock->acquire(true);
        try {
            $this->em->refresh($user);
            $details = $user->getPaymentDetails();
            // A verified address means the mailbox owner has taken the
            // account over through our link; the buyer's nonce no longer counts.
            if (isset($details[self::SIGNED_IN_KEY]) || $user->isEmailVerified()) {
                return false;
            }
            $details[self::SIGNED_IN_KEY] = time();
            $user->setPaymentDetails($details);
            $this->em->flush();

            return true;
        } finally {
            $lock->release();
        }
    }
}
