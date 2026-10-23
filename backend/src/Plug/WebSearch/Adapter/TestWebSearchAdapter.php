<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * Answers a web search from JSON fixtures. An unknown query is an empty set.
 *
 * Registered only in the test environment, or when a test constructs it.
 * It never calls the network.
 */
final readonly class TestWebSearchAdapter implements WebSearchProviderInterface
{
    public function __construct(
        private string $fixturesDir,
    ) {
    }

    public function key(): string
    {
        return 'test';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'test',
            'Test fixtures',
            'docs/E2E_TESTING.md',
            [],
            'test',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return new WebSearchCapabilities(
            freshness: false,
            country: false,
            language: false,
            siteFilter: false,
            fullContent: false,
            answer: false,
        );
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $needle = $this->normalise($query->query);
        foreach ($this->fixtures() as $fixture) {
            if ($this->normalise($fixture['query']) !== $needle) {
                continue;
            }

            return SearchResultSet::fromResults($query->query, $fixture['results']);
        }

        return SearchResultSet::empty($query->query);
    }

    public function health(): PlugHealth
    {
        return is_dir($this->fixturesDir)
            ? PlugHealth::available()
            : PlugHealth::unavailable('fixture directory is missing');
    }

    /**
     * @return list<array{query: string, results: list<SearchResult>}>
     */
    private function fixtures(): array
    {
        if (!is_dir($this->fixturesDir)) {
            return [];
        }

        $out = [];
        foreach (glob($this->fixturesDir.'/*.json') ?: [] as $path) {
            try {
                $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new \RuntimeException('Search fixture is not valid JSON: '.$path, 0, $e);
            }
            if (!\is_array($decoded) || !\is_string($decoded['query'] ?? null) || !\is_array($decoded['results'] ?? null)) {
                throw new \RuntimeException('Search fixture needs a query and a results list: '.$path);
            }
            $results = [];
            foreach ($decoded['results'] as $row) {
                if (!\is_array($row) || !\is_string($row['url'] ?? null) || '' === $row['url']) {
                    throw new \RuntimeException('Search fixture result needs a url: '.$path);
                }
                $results[] = new SearchResult(
                    \is_string($row['title'] ?? null) ? $row['title'] : '',
                    $row['url'],
                    \is_string($row['description'] ?? null) ? $row['description'] : '',
                );
            }
            $out[] = ['query' => $decoded['query'], 'results' => $results];
        }

        return $out;
    }

    private function normalise(string $query): string
    {
        $query = mb_strtolower(trim($query));

        return preg_replace('/\s+/u', ' ', $query) ?? $query;
    }
}
