<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Entity\User;

/**
 * What `/welcome` shows after a pay-first payment, and whom it signs in.
 */
final readonly class PayFirstClaimResult
{
    /** No pay-first session with this id. */
    public const NOT_FOUND = 'not_found';
    /** Stripe has not confirmed the payment yet; ask again shortly. */
    public const PENDING = 'pending';
    /** The account exists; `signIn` says whether this browser was signed in. */
    public const READY = 'ready';
    /** The address already had an account; the plan went onto it, nobody is signed in. */
    public const EXISTING = 'existing';
    /** The address has an account with another live subscription; nothing changed. */
    public const CONFLICT = 'conflict';

    public function __construct(
        public string $status,
        public string $plan = '',
        public string $email = '',
        public ?User $signIn = null,
    ) {
    }
}
