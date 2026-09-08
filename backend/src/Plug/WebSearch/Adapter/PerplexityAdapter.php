<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\AI\Provider\PerplexityProvider;
use App\Model\ModelCatalog;
use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\Client\PerplexitySearchClient;
use App\Plug\WebSearch\ProviderAnswer;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchOptionMapper;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use App\Repository\ConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * Perplexity Search API. `wantAnswer` additionally calls the catalog model
 * bound to DEFAULTMODEL.WEBANSWER when that row exists and is a Perplexity
 * model. S3 seeds no such binding — no hard-coded model name.
 *
 * @internal
 */
final readonly class PerplexityAdapter implements WebSearchProviderInterface
{
    public function __construct(
        private PerplexitySearchClient $client,
        private ConfigRepository $configRepository,
        private LoggerInterface $logger,
        private ?PerplexityProvider $chat = null,
    ) {
    }

    public function key(): string
    {
        return 'perplexity';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'perplexity',
            'Perplexity',
            'https://docs.perplexity.ai/guides/search-quickstart',
            ['PERPLEXITY_API_KEY'],
            'US cloud',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::perplexity();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $body = [
            'query' => $query->query,
            'max_results' => WebSearchOptionMapper::count($query->options),
        ];
        $range = WebSearchOptionMapper::timeRange(WebSearchOptionMapper::freshness($query->options));
        if (null !== $range) {
            $body['search_recency_filter'] = $range;
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
            $snippet = $row['snippet'] ?? $row['description'] ?? '';
            $published = $row['date'] ?? $row['publishedAt'] ?? null;
            $results[] = new SearchResult(
                title: \is_string($row['title'] ?? null) ? $row['title'] : '',
                url: $url,
                description: \is_string($snippet) ? $snippet : '',
                publishedAt: \is_string($published) ? $published : null,
            );
        }

        $answer = $query->wantAnswer ? $this->maybeAnswer($query->query, $results) : null;

        return SearchResultSet::fromResults($query->query, $results, [
            'provider' => $this->key(),
            'total' => count($results),
        ], $answer);
    }

    public function health(): PlugHealth
    {
        return $this->client->hasKey()
            ? PlugHealth::available()
            : PlugHealth::unavailable('Perplexity API key is not configured');
    }

    /**
     * @param list<SearchResult> $results
     */
    private function maybeAnswer(string $query, array $results): ?ProviderAnswer
    {
        $raw = $this->configRepository->getValue(0, 'DEFAULTMODEL', 'WEBANSWER');
        if (null === $raw || '' === trim($raw) || !is_numeric($raw)) {
            return null;
        }
        $bid = (int) $raw;
        $model = null;
        foreach (ModelCatalog::all() as $row) {
            if ((int) $row['id'] === $bid) {
                $model = $row;
                break;
            }
        }
        if (null === $model || 'perplexity' !== ModelCatalog::normalizeProvider((string) $model['service'])) {
            return null;
        }
        if (null === $this->chat || !$this->chat->isAvailable()) {
            return null;
        }

        $providerId = (string) $model['providerId'];
        try {
            $response = $this->chat->chat(
                [['role' => 'user', 'content' => $query]],
                ['model' => $providerId],
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Perplexity WEBANSWER chat failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        $text = trim($response['content']);
        if ('' === $text) {
            return null;
        }

        $citations = [];
        foreach ($results as $result) {
            $citations[] = new SearchResult(
                $result->title,
                $result->url,
                $result->description,
                SearchResult::KIND_ANSWER_CITATION,
                $result->content,
                $result->publishedAt,
            );
        }

        return new ProviderAnswer($text, $citations);
    }
}
