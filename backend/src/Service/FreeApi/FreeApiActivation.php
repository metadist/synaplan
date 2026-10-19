<?php

declare(strict_types=1);

namespace App\Service\FreeApi;

/**
 * What an activation did, in terms a person can be told.
 */
final readonly class FreeApiActivation
{
    /**
     * @param array<string, int|float> $limits
     * @param list<string>             $boundCapabilities DEFAULTMODEL purposes now answered by the Demo
     */
    public function __construct(
        public int $expiresAt,
        public int $daysLeft,
        public array $limits,
        public array $boundCapabilities,
        public bool $vectorizeKept,
    ) {
    }
}
