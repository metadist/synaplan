<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

final readonly class SourceDetails
{
    public function __construct(
        public ?string $published = null,
        public ?string $sourceClass = null,
        public ?string $reason = null,
        public ?string $readBy = null,
        public ?string $origin = null,
        public ?string $fromId = null,
    ) {
    }
}
