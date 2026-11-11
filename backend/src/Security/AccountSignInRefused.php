<?php

declare(strict_types=1);

namespace App\Security;

/**
 * A token was about to be minted for an account that may not sign in.
 * The door in front of the mint should already have refused; this is the
 * backstop so a missed door cannot hand out a session.
 */
final class AccountSignInRefused extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct(AccountAccess::SUSPENDED_MESSAGE);
    }
}
