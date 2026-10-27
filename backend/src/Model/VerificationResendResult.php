<?php

declare(strict_types=1);

namespace App\Model;

/**
 * What a verification resend did, for the HTTP layer to map onto JSON.
 */
final readonly class VerificationResendResult
{
    public function __construct(
        public VerificationResendStatus $status,
        public ?int $remainingAttempts = null,
        public ?int $waitSeconds = null,
        public ?string $nextAvailableAt = null,
        public int $cooldownMinutes = 0,
    ) {
    }

    public function maxAttemptsReached(): bool
    {
        return VerificationResendStatus::RateLimited === $this->status
            && (null === $this->remainingAttempts || $this->remainingAttempts <= 0);
    }
}
