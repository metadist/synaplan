<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * What the hourly scheduler run did: whether it left the Demo (and why) and
 * whether a key revoke is still waiting for the server.
 */
final readonly class FreeApiMaintenance
{
    public const LEFT_EXPIRED = 'expired';
    public const LEFT_DISABLED = 'disabled';

    public function __construct(
        public ?string $leftBecause,
        public bool $revokePending,
    ) {
    }
}
