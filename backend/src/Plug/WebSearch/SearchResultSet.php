<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * Provider-neutral search results. `toLegacyArray()` / `formatForAi()`
 * reproduce {@see \App\Service\Search\BraveSearchService} exactly (C1).
 *
 * @phpstan-type LegacySearchResult array<string, mixed>
 */
final readonly class SearchResultSet
{
    /**
     * @param list<LegacySearchResult> $results
     * @param array<string, mixed>     $meta
     * @param array<string, mixed>     $legacy
     */
    public function __construct(
        public string $query,
        public array $results,
        public array $meta,
        public array $legacy,
    ) {
    }

    /**
     * @param array<string, mixed> $legacy BraveSearchService::search() shape
     */
    public static function fromLegacyArray(array $legacy): self
    {
        $results = [];
        if (isset($legacy['results']) && \is_array($legacy['results'])) {
            foreach ($legacy['results'] as $row) {
                if (\is_array($row)) {
                    $results[] = $row;
                }
            }
        }

        return new self(
            \is_string($legacy['query'] ?? null) ? $legacy['query'] : '',
            $results,
            \is_array($legacy['query_metadata'] ?? null) ? $legacy['query_metadata'] : [],
            $legacy,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toLegacyArray(): array
    {
        return $this->legacy;
    }

    /**
     * Byte-identical to BraveSearchService::formatResultsForAI() for a complete
     * Brave payload. Missing provider-neutral keys fall back instead of
     * triggering undefined-index warnings.
     */
    public function formatForAi(): string
    {
        $legacy = $this->legacy;
        $results = isset($legacy['results']) && \is_array($legacy['results'])
            ? $legacy['results']
            : $this->results;
        if ([] === $results) {
            return 'No search results found for query: '.($legacy['query'] ?? 'unknown');
        }

        $query = \is_string($legacy['query'] ?? null) ? $legacy['query'] : $this->query;
        $meta = \is_array($legacy['query_metadata'] ?? null) ? $legacy['query_metadata'] : $this->meta;
        $total = $meta['total'] ?? \count($results);

        $formatted = "Web Search Results for: \"{$query}\"\n\n";
        $formatted .= "Found {$total} results:\n\n";

        foreach ($results as $index => $result) {
            if (!\is_array($result)) {
                continue;
            }
            $num = $index + 1;
            $title = \is_scalar($result['title'] ?? null) ? (string) $result['title'] : '';
            $url = \is_scalar($result['url'] ?? null) ? (string) $result['url'] : '';
            $formatted .= "[{$num}] {$title}\n";
            $formatted .= "URL: {$url}\n";

            if (!empty($result['description'])) {
                $formatted .= "Description: {$result['description']}\n";
            }

            if (!empty($result['age'])) {
                $formatted .= "Published: {$result['age']}\n";
            }

            if (!empty($result['extra_snippets']) && \is_array($result['extra_snippets'])) {
                $formatted .= "Snippets:\n";
                foreach ($result['extra_snippets'] as $snippet) {
                    $formatted .= '  - '.strip_tags((string) $snippet)."\n";
                }
            }

            $formatted .= "\n";
        }

        return $formatted;
    }
}
