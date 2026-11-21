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
        $q = WebSearchOptionMapper::applySiteFilter($query->query, WebSearchOptionMapper::site($query->options));
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

        $payload = $this->client->search($params);
        $data = \is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $passagesByUrl = [];
        foreach ($data['passages'] ?? [] as $passage) {
            if (!\is_array($passage)) {
                continue;
            }
            $passageUrl = \is_string($passage['url'] ?? null) ? $passage['url'] : '';
            $passageText = \is_string($passage['text'] ?? null) ? $passage['text'] : '';
            if ('' !== $passageUrl && '' !== trim($passageText)) {
                $passagesByUrl[$passageUrl] = isset($passagesByUrl[$passageUrl])
                    ? $passagesByUrl[$passageUrl]."\n\n".$passageText
                    : $passageText;
            }
        }

        $results = [];
        foreach ($data['sources'] ?? [] as $row) {
            if (!\is_array($row)) {
                continue;
            }
            $url = \is_string($row['url'] ?? null) ? $row['url'] : '';
            if ('' === $url) {
                continue;
            }
            $snippet = \is_string($row['snippet'] ?? null) ? $row['snippet'] : '';
            $excerpt = \is_string($row['excerpt'] ?? null) && '' !== trim($row['excerpt'])
                ? $row['excerpt']
                : ($passagesByUrl[$url] ?? null);
            $published = $row['published'] ?? null;

            $results[] = new SearchResult(
                title: \is_string($row['title'] ?? null) ? $row['title'] : '',
                url: $url,
                description: '' !== $snippet ? $snippet : ($excerpt ?? ''),
                content: WebSearchOptionMapper::truncate($excerpt, $maxChars),
                publishedAt: \is_string($published) ? $published : null,
            );
        }

        $answer = null;
        if ($query->wantAnswer) {
            $answerValue = $this->extractAnswerText($data['answer'] ?? null);
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
}
