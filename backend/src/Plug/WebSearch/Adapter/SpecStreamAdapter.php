<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\SpecStreamClient;
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
final readonly class SpecStreamAdapter implements WebSearchProviderInterface, WebSearchLiveProbeInterface
{
    public function __construct(
        private SpecStreamClient $client,
        private PlugConfigService $plugConfig,
    ) {
    }

    public function key(): string
    {
        return 'specstream';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'specstream',
            'SpecStream',
            'https://specstream.ai/docs/search-api',
            ['SPECSTREAM_API_KEY'],
            'EU',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::specstream();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $maxChars = $this->plugConfig->webSearchMaxContentChars();
        $payload = $this->client->search($this->params($query));
        $data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $passages = $this->passagesByUrl($data['passages'] ?? null, $maxChars);

        $results = [];
        foreach ($data['sources'] ?? [] as $row) {
            $result = $this->result($row, $passages, $maxChars);
            if (null !== $result) {
                $results[] = $result;
            }
        }

        $answer = null;
        if ($query->wantAnswer) {
            $answerValue = self::bounded($this->extractAnswerText($data['answer'] ?? null), $maxChars);
            if (null !== $answerValue) {
                $answer = new ProviderAnswer($answerValue);
            }
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
            : PlugHealth::unavailable('SpecStream API key is not configured');
    }

    public function probe(): PlugHealth
    {
        return WebSearchProbe::run(
            $this->client->hasKey(),
            'SpecStream API key is not configured',
            $this->client->probe(...),
        );
    }

    /**
     * @return array<string, int|string>
     */
    private function params(WebSearchQuery $query): array
    {
        $q = WebSearchOptionMapper::applySiteFilter(
            $query->query,
            self::siteHost(WebSearchOptionMapper::site($query->options)),
        );
        $start = WebSearchOptionMapper::startPublishedDate(WebSearchOptionMapper::freshness($query->options));
        if (null !== $start && !str_contains(strtolower($q), 'after:')) {
            $q .= ' after:'.$start;
        }

        $params = [
            'q' => $q,
            'num' => WebSearchOptionMapper::count($query->options),
            'passages' => 'true',
        ];
        if (!$query->wantAnswer) {
            $params['speed'] = 'fast';
        }

        $language = WebSearchOptionMapper::language($query->options);
        if (null !== $language && 1 === preg_match('/^[a-z]{2}$/', $language)) {
            $params['language'] = $language;
        }

        $country = WebSearchOptionMapper::country($query->options);
        if (null !== $country) {
            $params['country'] = $country;
        }

        return $params;
    }

    /**
     * @return array<string, string>
     */
    private function passagesByUrl(mixed $passages, int $maxChars): array
    {
        if (!\is_array($passages)) {
            return [];
        }

        $byUrl = [];
        foreach ($passages as $passage) {
            if (!\is_array($passage)) {
                continue;
            }
            $url = self::publicHttpUrl(\is_string($passage['url'] ?? null) ? $passage['url'] : '');
            $text = \is_string($passage['text'] ?? null) ? trim($passage['text']) : '';
            if (null === $url || '' === $text) {
                continue;
            }
            $joined = isset($byUrl[$url]) ? $byUrl[$url]."\n\n".$text : $text;
            $byUrl[$url] = self::bounded($joined, $maxChars) ?? '';
        }

        return $byUrl;
    }

    /**
     * @param array<string, string> $passages
     */
    private function result(mixed $row, array $passages, int $maxChars): ?SearchResult
    {
        if (!\is_array($row)) {
            return null;
        }
        $url = self::publicHttpUrl(\is_string($row['url'] ?? null) ? $row['url'] : '');
        if (null === $url) {
            return null;
        }
        $snippet = \is_string($row['snippet'] ?? null) ? $row['snippet'] : '';
        $excerpt = \is_string($row['excerpt'] ?? null) && '' !== trim($row['excerpt'])
            ? $row['excerpt']
            : ($passages[$url] ?? null);
        $published = $row['published'] ?? null;
        $description = '' !== trim($snippet) ? $snippet : ($excerpt ?? '');

        return new SearchResult(
            title: \is_string($row['title'] ?? null) ? $row['title'] : '',
            url: $url,
            description: self::bounded($description, $maxChars) ?? '',
            content: self::bounded(\is_string($excerpt) ? $excerpt : null, $maxChars),
            publishedAt: \is_string($published) ? $published : null,
        );
    }

    private function extractAnswerText(mixed $raw): ?string
    {
        if (\is_string($raw) && '' !== trim($raw)) {
            return trim($raw);
        }
        if (\is_array($raw) && \is_string($raw['value'] ?? null) && '' !== trim($raw['value'])) {
            return trim($raw['value']);
        }

        return null;
    }

    private static function bounded(?string $text, int $maxChars): ?string
    {
        return WebSearchOptionMapper::truncate($text, $maxChars);
    }

    /**
     * A site filter is one hostname. Anything else would be appended as a
     * search operator and could widen the query.
     */
    private static function siteHost(?string $site): ?string
    {
        if (null === $site) {
            return null;
        }
        $site = strtolower(trim($site));
        if (1 !== preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $site)) {
            return null;
        }

        return $site;
    }

    /**
     * Search hits are shown and may be fetched later. Only a public http(s)
     * URL is kept; credentials, private addresses and other schemes are not.
     */
    private static function publicHttpUrl(string $url): ?string
    {
        $url = trim($url);
        if ('' === $url || str_contains($url, '\\') || str_contains($url, "\n") || str_contains($url, "\r")) {
            return null;
        }
        $parts = parse_url($url);
        if (!\is_array($parts)) {
            return null;
        }
        $scheme = strtolower(\is_string($parts['scheme'] ?? null) ? $parts['scheme'] : '');
        $host = strtolower(\is_string($parts['host'] ?? null) ? $parts['host'] : '');
        if (!\in_array($scheme, ['http', 'https'], true) || '' === $host || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }
        if ('localhost' === $host || str_ends_with($host, '.localhost') || str_ends_with($host, '.local') || str_ends_with($host, '.internal')) {
            return null;
        }
        if (false !== filter_var($host, \FILTER_VALIDATE_IP)
            && false === filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_NO_PRIV_RANGE | \FILTER_FLAG_NO_RES_RANGE)) {
            return null;
        }

        return $url;
    }
}
