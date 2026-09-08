<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\SearxngClient;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * @internal
 */
final readonly class SearxngAdapter implements WebSearchProviderInterface
{
    public function __construct(
        private SearxngClient $client,
    ) {
    }

    public function key(): string
    {
        return 'searxng';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'searxng',
            'SearXNG',
            'https://docs.searxng.org/',
            ['SEARXNG_BASE_URL'],
            'self-hosted',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::searxng();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $freshness = WebSearchOptionMapper::freshness($query->options);
        $language = WebSearchOptionMapper::language($query->options);
        $q = WebSearchOptionMapper::applySiteFilter($query->query, WebSearchOptionMapper::site($query->options));

        $params = [
            'q' => $q,
            'format' => 'json',
            'safesearch' => 1,
            'pageno' => 1,
        ];
        if (null !== $language) {
            $params['language'] = $language;
        }
        $timeRange = WebSearchOptionMapper::timeRange($freshness);
        if (null !== $timeRange) {
            $params['time_range'] = $timeRange;
        }

        $payload = $this->client->search($params);
        $results = [];
        foreach ($payload['results'] ?? [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = \is_string($row['url'] ?? null) ? $row['url'] : '';
            if ('' === $url) {
                continue;
            }
            $published = $row['publishedDate'] ?? null;
            $results[] = new SearchResult(
                title: \is_string($row['title'] ?? null) ? $row['title'] : '',
                url: $url,
                description: \is_string($row['content'] ?? null) ? $row['content'] : '',
                publishedAt: \is_string($published) ? $published : null,
            );
        }

        $total = $payload['number_of_results'] ?? count($results);

        return SearchResultSet::fromResults($query->query, $results, [
            'provider' => $this->key(),
            'total' => \is_numeric($total) ? (int) $total : count($results),
        ]);
    }

    public function health(): PlugHealth
    {
        return $this->client->isConfigured()
            ? PlugHealth::available()
            : PlugHealth::unavailable('SEARXNG_BASE_URL is unset or disabled');
    }
}
