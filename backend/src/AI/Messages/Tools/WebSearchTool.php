<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

use App\Service\Search\BraveSearchService;
use Psr\Log\LoggerInterface;

/**
 * Synaplan's own web search, exposed to the Messages gateway as a tool the
 * upstream model can call.
 *
 * Anthropic ships web search as a *server* tool: the client declares
 * `{"type": "web_search_20250305", "name": "web_search"}` and expects the API
 * side to run the search. Only api.anthropic.com can honour that declaration,
 * so every other route through the gateway (OpenAI, Gemini, or an Anthropic key
 * whose org has no web search entitlement) used to silently answer from
 * training data. This tool closes that gap: the declaration is replaced by an
 * executable function tool of the same name and Synaplan runs the search
 * itself, so behaviour no longer depends on the upstream provider.
 */
final readonly class WebSearchTool
{
    /**
     * Tool name presented to the model. Deliberately identical to Anthropic's
     * server-tool name so a client that declared `web_search` sees the results
     * it asked for and prompt wording keeps working.
     */
    public const NAME = 'web_search';

    private const DEFAULT_MAX_RESULTS = 5;
    private const MAX_RESULTS_CAP = 10;
    private const MAX_QUERY_CHARS = 400;
    private const FRESHNESS_VALUES = ['pd', 'pw', 'pm', 'py'];

    public function __construct(
        private BraveSearchService $braveSearch,
        private LoggerInterface $logger,
    ) {
    }

    public function isAvailable(): bool
    {
        return $this->braveSearch->isEnabled();
    }

    /**
     * Anthropic `tools[]` entry for the executable replacement.
     *
     * @return array{name: string, description: string, input_schema: array<string, mixed>}
     */
    public function declaration(): array
    {
        return [
            'name' => self::NAME,
            'description' => 'Search the live web and get back ranked results with title, URL and snippet. '
                .'Use it whenever the answer depends on current events, prices, releases, documentation or '
                .'anything else that may have changed since training. Cite the URLs you used in your answer.',
            'input_schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => [
                        'type' => 'string',
                        'description' => 'Search query, phrased the way you would type it into a search engine.',
                    ],
                    'max_results' => [
                        'type' => 'integer',
                        'description' => 'How many results to return (1-'.self::MAX_RESULTS_CAP.').',
                        'minimum' => 1,
                        'maximum' => self::MAX_RESULTS_CAP,
                    ],
                    'language' => [
                        'type' => 'string',
                        'description' => 'ISO 639-1 language code to search in, e.g. "en" or "de".',
                    ],
                    'freshness' => [
                        'type' => 'string',
                        'description' => 'Restrict to recent results: pd (past day), pw (week), pm (month), py (year).',
                        'enum' => self::FRESHNESS_VALUES,
                    ],
                ],
                'required' => ['query'],
            ],
        ];
    }

    /**
     * Run one search. Never throws: a failed search comes back as an error
     * tool result so the model can recover in the same turn.
     *
     * @param array<string, mixed> $input
     *
     * @return array{text: string, isError: bool, query: string, resultCount: int}
     */
    public function execute(array $input): array
    {
        $query = \is_string($input['query'] ?? null) ? trim($input['query']) : '';
        if ('' === $query) {
            return $this->error('', 'web_search requires a non-empty `query`.');
        }
        if (mb_strlen($query) > self::MAX_QUERY_CHARS) {
            $query = mb_substr($query, 0, self::MAX_QUERY_CHARS);
        }

        if (!$this->isAvailable()) {
            return $this->error($query, 'Web search is not configured on this Synaplan instance.');
        }

        try {
            $results = $this->braveSearch->search($query, $this->searchOptions($input));
        } catch (\Throwable $e) {
            $this->logger->warning('WebSearchTool: search failed', [
                'query' => $query,
                'error' => $e->getMessage(),
            ]);

            return $this->error($query, 'Web search failed: '.$e->getMessage());
        }

        $count = \count($results['results'] ?? []);
        $this->logger->info('WebSearchTool: search completed', [
            'query' => $query,
            'results' => $count,
        ]);

        return [
            'text' => $this->braveSearch->formatResultsForAI($results),
            'isError' => false,
            'query' => $query,
            'resultCount' => $count,
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function searchOptions(array $input): array
    {
        $options = [];

        $maxResults = $input['max_results'] ?? null;
        if (is_numeric($maxResults)) {
            $options['count'] = max(1, min(self::MAX_RESULTS_CAP, (int) $maxResults));
        } else {
            $options['count'] = self::DEFAULT_MAX_RESULTS;
        }

        $language = $input['language'] ?? null;
        if (\is_string($language) && '' !== trim($language)) {
            // BraveSearchService normalises and falls back on anything invalid.
            $options['search_lang'] = trim($language);
            $options['country'] = trim($language);
        }

        $freshness = $input['freshness'] ?? null;
        if (\is_string($freshness) && \in_array($freshness, self::FRESHNESS_VALUES, true)) {
            $options['freshness'] = $freshness;
        }

        return $options;
    }

    /**
     * @return array{text: string, isError: true, query: string, resultCount: int}
     */
    private function error(string $query, string $message): array
    {
        return [
            'text' => $message,
            'isError' => true,
            'query' => $query,
            'resultCount' => 0,
        ];
    }
}
