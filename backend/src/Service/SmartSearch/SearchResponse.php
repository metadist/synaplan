<?php

declare(strict_types=1);

namespace App\Service\SmartSearch;

final readonly class SearchResponse
{
    /**
     * @param list<SearchHit> $hits
     * @param list<string>    $degraded providers that were skipped or failed
     */
    public function __construct(
        public string $query,
        public array $hits,
        public bool $semanticAvailable,
        public array $degraded,
        public bool $indexing,
    ) {
    }

    /**
     * @return array{query: string, results: list<array{id: string, kind: string, title: string, subtitle: ?string, snippet: ?string, route: string, score: float, matchedBy: string}>, semanticAvailable: bool, degraded: list<string>, indexing: bool}
     */
    public function toArray(): array
    {
        return [
            'query' => $this->query,
            'results' => array_map(static fn (SearchHit $hit): array => $hit->toArray(), $this->hits),
            'semanticAvailable' => $this->semanticAvailable,
            'degraded' => $this->degraded,
            'indexing' => $this->indexing,
        ];
    }
}
