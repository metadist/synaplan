<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Entity\User;

/**
 * What a paid pay-first session led to.
 */
final readonly class PaidAccountOutcome
{
    /** The payment is not in yet; nothing was created. */
    public const PENDING = 'pending';
    /** A new account, made from this session. */
    public const CREATED = 'created';
    /** The address already had an account; the plan went onto it. */
    public const EXISTING = 'existing';
    /** The address has an account with another live subscription; nothing changed, an operator refunds. */
    public const CONFLICT = 'conflict';

    private function __construct(
        public string $kind,
        public ?User $user,
        public string $plan,
    ) {
    }

    public static function pending(string $plan): self
    {
        return new self(self::PENDING, null, $plan);
    }

    public static function created(User $user, string $plan): self
    {
        return new self(self::CREATED, $user, $plan);
    }

    public static function existing(User $user, string $plan): self
    {
        return new self(self::EXISTING, $user, $plan);
    }

    public static function conflict(User $user, string $plan): self
    {
        return new self(self::CONFLICT, $user, $plan);
    }
}
