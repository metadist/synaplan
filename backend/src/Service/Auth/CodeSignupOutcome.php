<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Entity\User;

/**
 * What opening an invitation link did: a new account at the code's tier, or
 * a sign-in to the account the address already had (the code stays unused).
 */
final readonly class CodeSignupOutcome
{
    public function __construct(
        public User $user,
        public bool $created,
    ) {
    }
}
