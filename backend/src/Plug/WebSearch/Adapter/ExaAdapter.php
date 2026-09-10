<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\ExaClient;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProbe;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * @internal
 */
final readonly class ExaAdapter implements WebSearchProviderInterface
{
    public function __construct(
        private ExaClient $client,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function key(): string
    {
        return 'exa';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'exa',
            'Exa',
            'https://docs.exa.ai/reference/search',
            ['EXA_API_KEY'],
            'US cloud',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::exa();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $maxChars = $this->plugConfig->webSearchMaxContentChars();
        $body = [
            'query' => $query->query,
            'numResults' => WebSearchOptionMapper::count($query->options),
            'type' => 'auto',
            'contents' => ['text' => ['maxCharacters' => $maxChars]],
        ];
        $site = WebSearchOptionMapper::site($query->options);
        if (null !== $site) {
            $body['includeDomains'] = [$site];
        }
        $start = WebSearchOptionMapper::startPublishedDate(WebSearchOptionMapper::freshness($query->options));
        if (null !== $start) {
            $body['startPublishedDate'] = $start;
        }

        $payload = $this->client->search($body);
        $results = [];
        foreach ($payload['results'] ?? [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = \is_string($row['url'] ?? null) ? $row['url'] : '';
            if ('' === $url) {
                continue;
            }
            $text = \is_string($row['text'] ?? null) ? $row['text'] : null;
            $published = $row['publishedDate'] ?? null;
            $results[] = new SearchResult(
                title: \is_string($row['title'] ?? null) ? $row['title'] : '',
                url: $url,
                description: $text ?? '',
                content: WebSearchOptionMapper::truncate($text, $maxChars),
                publishedAt: \is_string($published) ? $published : null,
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
            : PlugHealth::unavailable('Exa API key is not configured');
    }

    public function probe(): PlugHealth
    {
        return WebSearchProbe::run(
            $this->client->hasKey(),
            'Exa API key is not configured',
            $this->client->probe(...),
        );
    }
}
