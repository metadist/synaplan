<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProvisionDefaultUserPluginsMessage
{
    public const REASON_SOCIAL_SIGNUP = 'social_signup';
    public const REASON_EMAIL_VERIFIED = 'email_verified';

    public function __construct(
        private int $userId,
        private string $reason,
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
