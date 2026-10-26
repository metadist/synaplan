<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/**
 * Catalog keys a desktop project sends for the roles it picked.
 * A null role is filled by the server from the slots, inside the world.
 */
final readonly class ResearchModelHints
{
    public function __construct(
        public ?string $lead = null,
        public ?string $writer = null,
        public ?string $reader = null,
        public ?string $checker = null,
        public ?string $sorter = null,
        public ?string $thoroughLead = null,
    ) {
    }
}
