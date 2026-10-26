<?php

declare(strict_types=1);

namespace App\Message;

/**
 * Mail the invitation-code link for this address. Queued for every valid
 * code, so the HTTP answer takes the same time whether or not the address
 * already has an account.
 */
final readonly class SendCodeSignupMessage
{
    public function __construct(
        public string $email,
        public int $codeId,
        public string $locale,
    ) {
    }
}
