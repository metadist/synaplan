<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\TavilyClient;
use App\Plug\WebSearch\ProviderAnswer;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchLiveProbeInterface;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProbe;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * @internal
 */
final readonly class TavilyAdapter implements WebSearchProviderInterface, WebSearchLiveProbeInterface
{
    public function __construct(
        private TavilyClient $client,
    ) {
    }

    public function key(): string
    {
        return 'tavily';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'tavily',
            'Tavily',
            'https://docs.tavily.com/documentation/api-reference/endpoint/search',
            ['TAVILY_API_KEY'],
            'US cloud',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::tavily();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $body = [
            'query' => $query->query,
            'max_results' => WebSearchOptionMapper::count($query->options),
            'search_depth' => 'basic',
            'include_answer' => $query->wantAnswer,
            'topic' => 'general',
        ];
        $days = WebSearchOptionMapper::freshnessDays(WebSearchOptionMapper::freshness($query->options));
        if (null !== $days) {
            $body['days'] = $days;
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
            $published = $row['published_date'] ?? null;
            $content = \is_string($row['content'] ?? null) ? $row['content'] : null;
            $results[] = new SearchResult(
                title: \is_string($row['title'] ?? null) ? $row['title'] : '',
                url: $url,
                description: $content ?? '',
                content: $content,
                publishedAt: \is_string($published) ? $published : null,
            );
        }

        $answer = null;
        if ($query->wantAnswer && \is_string($payload['answer'] ?? null) && '' !== trim($payload['answer'])) {
            $answer = new ProviderAnswer($payload['answer']);
        }

        return SearchResultSet::fromResults($query->query, $results, [
            'provider' => $this->key(),
            'total' => count($results),
        ], $answer);
    }

    public function health(): PlugHealth
    {
        return $this->client->hasKey()
            ? PlugHealth::available()
            : PlugHealth::unavailable('Tavily API key is not configured');
    }

    public function probe(): PlugHealth
    {
        return WebSearchProbe::run(
            $this->client->hasKey(),
            'Tavily API key is not configured',
            $this->client->probe(...),
        );
    }
}
