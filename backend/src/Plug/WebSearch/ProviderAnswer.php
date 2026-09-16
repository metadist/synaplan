<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * Optional synthesized answer from a provider that supports `capabilities.answer`.
 */
final readonly class ProviderAnswer
{
    /**
     * @param list<SearchResult> $citations
     */
    public function __construct(
        public string $text,
        public array $citations = [],
    ) {
    }
}
