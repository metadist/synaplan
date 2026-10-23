<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final readonly class Finding
{
    /**
     * @param list<string> $passageIds
     */
    public function __construct(
        public string $fact,
        public array $passageIds,
        public ?string $quote,
    ) {
    }
}
