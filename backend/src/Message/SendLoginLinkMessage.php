<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mail a one-time sign-in link to this address if it has an account.
 *
 * Queued for every request, known address or not: the account lookup, the
 * token and the mail happen in the worker, so the HTTP answer takes the same
 * time either way and cannot reveal which addresses have accounts.
 */
final readonly class SendLoginLinkMessage
{
    public function __construct(
        public string $email,
    ) {
    }
}
