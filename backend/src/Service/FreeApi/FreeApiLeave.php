<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * What leaving did: which purposes got their previous models back and
 * whether the server already knows the key is gone (otherwise the scheduler
 * delivers the revoke later).
 */
final readonly class FreeApiLeave
{
    /**
     * @param list<string> $restoredCapabilities
     */
    public function __construct(
        public bool $wasActive,
        public array $restoredCapabilities,
        public bool $keyRevoked,
    ) {
    }
}
