<?php

declare(strict_types=1);

namespace App\Service\SelfAware;

/**
 * One live (or known-absent) capability of this installation, for this user.
 */
final readonly class CapabilityFact
{
    public function __construct(
        public string $id,
        public string $label,
        public CapabilityState $state,
        public string $detail,
        public ?string $alternative,
        public ?string $adminHint,
        public ?string $docsSlug,
    ) {
    }
}
