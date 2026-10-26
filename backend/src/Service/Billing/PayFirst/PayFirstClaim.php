<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Entity\User;
use App\Repository\VerificationTokenRepository;
use Doctrine\ORM\EntityManagerInterface;

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
    public function __construct(
        private CheckoutSessionGateway $gateway,
        private PaidAccountCreator $creator,
        private VerificationTokenRepository $tokens,
        private EntityManagerInterface $em,
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

        // A verified address means the mailbox owner has taken the account
        // over through our link; the buyer's nonce no longer counts.
        $this->em->refresh($user);
        if ($user->isEmailVerified()) {
            return false;
        }

        // The grant is its own row, used up by one atomic update: two claims
        // at once, or a billing write with an older snapshot, cannot hand
        // out a second sign-in.
        $grant = $this->tokens->findValidToken(PaidAccountCreator::signInGrantKey($checkout), PaidAccountCreator::SIGN_IN_GRANT);

        return null !== $grant && $this->tokens->claimUnused($grant);
    }
}
