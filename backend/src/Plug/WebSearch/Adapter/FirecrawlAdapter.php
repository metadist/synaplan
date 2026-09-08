<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\FirecrawlClient;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * @internal
 */
final readonly class FirecrawlAdapter implements WebSearchProviderInterface
{
    public function __construct(
        private FirecrawlClient $client,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function key(): string
    {
        return 'firecrawl';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'firecrawl',
            'Firecrawl',
            'https://docs.firecrawl.dev/features/search',
            ['FIRECRAWL_API_KEY'],
            'US cloud',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::firecrawl();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $maxChars = $this->plugConfig->webSearchMaxContentChars();
        $payload = $this->client->search([
            'query' => $query->query,
            'limit' => WebSearchOptionMapper::count($query->options),
            'scrapeOptions' => ['formats' => ['markdown']],
        ]);

        $results = [];
        foreach ($payload['data'] ?? [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = \is_string($row['url'] ?? null) ? $row['url'] : '';
            if ('' === $url) {
                continue;
            }
            $markdown = \is_string($row['markdown'] ?? null) ? $row['markdown'] : null;
            $description = \is_string($row['description'] ?? null) ? $row['description'] : ($markdown ?? '');
            $results[] = new SearchResult(
                title: \is_string($row['title'] ?? null) ? $row['title'] : '',
                url: $url,
                description: $description,
                content: WebSearchOptionMapper::truncate($markdown, $maxChars),
            );
        }

        return SearchResultSet::fromResults($query->query, $results, [
            'provider' => $this->key(),
            'total' => count($results),
        ]);
    }

    public function health(): PlugHealth
    {
        return $this->client->hasKey()
            ? PlugHealth::available()
            : PlugHealth::unavailable('Firecrawl API key is not configured');
    }
}
