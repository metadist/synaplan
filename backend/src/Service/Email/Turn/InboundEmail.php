<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use App\Entity\User;

/**
 * One inbound email the intake accepted: who sent it to which address, what
 * it says, where it sits in its thread, and the account it answers for.
 */
final readonly class InboundEmail
{
    public const DEBUG_DOMAIN = '@metadist.onmicrosoft.com';

    /**
     * @param array<mixed> $attachments the relay's attachment list
     */
    public function __construct(
        public string $fromEmail,
        public string $toEmail,
        public string $subject,
        public string $body,
        public EmailThread $thread,
        public ?string $keyword,
        public string $fingerprint,
        public array $attachments,
        public User $user,
        public bool $isAnonymous,
    ) {
    }

    /**
     * A sender on the debug domain has every outcome reported to Discord.
     */
    public function reportsToDiscord(): bool
    {
        return str_ends_with($this->fromEmail, self::DEBUG_DOMAIN);
    }
}
