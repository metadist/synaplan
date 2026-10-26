<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final readonly class PageBody
{
    /**
     * @param list<string> $links
     */
    public function __construct(
        public string $text,
        public array $links,
        public ?string $blocked = null,
    ) {
    }
}
