<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final readonly class SearchHit
{
    public function __construct(
        public string $url,
        public string $title,
        public string $snippet,
    ) {
    }
}
