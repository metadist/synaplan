<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Stage;

/** One opened page. Links are only those the page itself listed. */
final readonly class ResearchPage
{
    /**
     * @param list<string> $links
     */
    public function __construct(
        public bool $blocked,
        public string $text,
        public string $reason,
        public array $links,
    ) {
    }
}
