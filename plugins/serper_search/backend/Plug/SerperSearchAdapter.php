<?php

declare(strict_types=1);

namespace Plugin\SerperSearch\Plug;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\PlugKeyStore;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Reference plug adapter: Google results via the Serper API.
 *
 * Everything a third-party plug adapter needs and nothing a core class does:
 * it reaches Synaplan only through the web-search port (interface + DTOs) and
 * {@see PlugKeyStore}, declares itself in manifest.json `provides.plugs`, and
 * fails soft (an unavailable key or a bad response yields an empty set, never
 * an exception that breaks chat).
 */
final readonly class SerperSearchAdapter implements WebSearchProviderInterface
{
    public const KEY = 'serper';

    private const ENDPOINT = 'https://google.serper.dev/search';

    public function __construct(
        private HttpClientInterface $httpClient,
        private PlugKeyStore $keys,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            self::KEY,
            'Serper (Google)',
            'https://serper.dev/',
            [],
            'US cloud',
            'serper_search',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return new WebSearchCapabilities(
            freshness: true,
            country: true,
            language: true,
            siteFilter: true,
            fullContent: false,
            answer: false,
        );
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $key = $this->keys->getKey(self::KEY);
        if (null === $key || '' === $key) {
            return SearchResultSet::empty($query->query, ['provider' => self::KEY]);
        }

        $site = WebSearchOptionMapper::site($query->options);
        $body = [
            'q' => WebSearchOptionMapper::applySiteFilter($query->query, $site),
            'num' => WebSearchOptionMapper::count($query->options),
        ];
        $country = $this->country($query->options);
        if (null !== $country) {
            $body['gl'] = $country;
        }
        $language = WebSearchOptionMapper::language($query->options);
        if (null !== $language) {
            $body['hl'] = $language;
        }
        $tbs = $this->tbs(WebSearchOptionMapper::freshness($query->options));
        if (null !== $tbs) {
            $body['tbs'] = $tbs;
        }

        $response = $this->httpClient->request('POST', self::ENDPOINT, [
            'timeout' => 10,
            'headers' => ['X-API-KEY' => $key, 'Content-Type' => 'application/json'],
            'json' => $body,
        ]);
        if ($response->getStatusCode() >= 400) {
            throw new \RuntimeException('Serper HTTP '.$response->getStatusCode());
        }

        return SerperSearchResultMapper::map($query->query, $response->toArray(false), self::KEY);
    }

    public function health(): PlugHealth
    {
        $key = $this->keys->getKey(self::KEY);

        return null !== $key && '' !== $key
            ? PlugHealth::available()
            : PlugHealth::unavailable('Serper API key is not configured');
    }

    /**
     * @param array<string, mixed> $options
     */
    private function country(array $options): ?string
    {
        $country = $options['country'] ?? $options['gl'] ?? null;
        if (!is_string($country) || '' === trim($country)) {
            return null;
        }

        return strtolower(substr(trim($country), 0, 2));
    }

    private function tbs(?string $freshness): ?string
    {
        return match (WebSearchOptionMapper::timeRange($freshness)) {
            'day' => 'qdr:d',
            'week' => 'qdr:w',
            'month' => 'qdr:m',
            'year' => 'qdr:y',
            default => null,
        };
    }
}
