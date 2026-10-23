<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final readonly class SpikeLimits
{
    public function __construct(
        public int $searches,
        public int $sources,
        public int $turns = 4,
        public int $resultsPerSearch = 5,
    ) {
    }

    public static function standard(): self
    {
        return new self(10, 15);
    }
}
