<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mail a password reset link to this address if it has an account that may
 * reset its password.
 *
 * Queued for every accepted request, known address or not: the account
 * lookup, the token and the mail happen in the worker, so the HTTP answer
 * takes the same time either way and cannot reveal which addresses have
 * accounts.
 */
final readonly class SendPasswordResetMessage
{
    public function __construct(
        public string $email,
    ) {
    }
}
