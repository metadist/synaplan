<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

final readonly class ResearchModelChoice
{
    public function __construct(
        public int $id,
        public string $provider,
        public string $family,
        public bool $allowed,
        public bool $healthy,
        public bool $retired,
        public ?int $successorId,
        public ?int $contextWindow,
    ) {
    }
}
