<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Repository\SearchResultRepository;
use App\Service\Message\SearchQueryGenerator;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Search\BraveSearchService;
use Psr\Log\LoggerInterface;

/**
 * `web_search` runner — reuses the existing {@see SearchQueryGenerator} (to turn
 * the request into an optimized query) + {@see BraveSearchService} (the live web
 * search) instead of adding new search code.
 *
 * The node output is the formatted, source-cited results block
 * ({@see BraveSearchService::formatResultsForAI()}). A downstream `chat`/
 * `summarize` node typically consumes `$nX.text` to write the final answer; when
 * web_search is the reply node, the user sees the result list directly. The raw
 * structured results also ride in metadata for any later consumer.
 */
final readonly class WebSearchRunner implements TaskRunner
{
    public function __construct(
        private SearchQueryGenerator $queryGenerator,
        private BraveSearchService $braveSearch,
        private LoggerInterface $logger,
        private ?SearchResultRepository $searchResultRepository = null,
    ) {
    }

    public function supportedCapabilities(): array
    {
        return [Capability::WebSearch];
    }

    public function run(TaskNode $node, NodeContext $context): NodeResult
    {
        if (!$this->braveSearch->isEnabled()) {
            return NodeResult::failed('web search is not configured');
        }

        $inputs = $context->resolveInputs($node);
        $request = $this->stringInput($inputs['query'] ?? $inputs['prompt'] ?? $inputs['text'] ?? null)
            ?? (string) $context->message->getText();
        if ('' === trim($request)) {
            return NodeResult::failed('no query for web_search');
        }

        // MessageProcessor (step 2.5) may have already searched for this turn —
        // it runs BEFORE the planner, so a classifier `BWEBSEARCH` vote plus a
        // planned web_search node would otherwise hit Brave twice for the same
        // message. Reuse the pre-fetched results, but only when this node is
        // searching the whole message (no planner-narrowed sub-query).
        $preFetched = $context->options['search_results'] ?? null;
        if (
            is_array($preFetched)
            && !empty($preFetched['results'])
            && trim($request) === trim((string) $context->message->getText())
        ) {
            $this->logger->info('WebSearchRunner: reusing pre-fetched search results', [
                'query' => $preFetched['query'] ?? null,
            ]);

            return NodeResult::ok($this->braveSearch->formatResultsForAI($preFetched), [], [
                'web_search' => true,
                'query' => is_string($preFetched['query'] ?? null) ? $preFetched['query'] : '',
                'search_results' => $preFetched,
                'reused_prefetched' => true,
            ]);
        }

        $language = is_string($context->classification['language'] ?? null)
            ? $context->classification['language']
            : ($context->message->getLanguage() ?: 'en');

        $query = $this->queryGenerator->generate($request, $context->userId);

        try {
            $results = $this->braveSearch->search($query, ['search_lang' => $language]);
        } catch (\Throwable $e) {
            $this->logger->warning('WebSearchRunner: search failed', [
                'error' => $e->getMessage(),
            ]);

            return NodeResult::failed('web_search failed: '.$e->getMessage());
        }

        $text = $this->braveSearch->formatResultsForAI($results);

        // Persist the structured results to the DB so MessageApiFormatter can
        // build the Sources dropdown on reload — mirrors what MessageProcessor
        // does on the single-task path. The reused_prefetched branch above skips
        // this because MessageProcessor has already saved the rows.
        if (null !== $this->searchResultRepository && !empty($results['results'])) {
            try {
                $this->searchResultRepository->saveSearchResults($context->message, $results, $query);
            } catch (\Throwable $e) {
                $this->logger->warning('WebSearchRunner: failed to persist search results (ignored)', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return NodeResult::ok($text, [], [
            'web_search' => true,
            'query' => $query,
            'search_results' => $results,
        ]);
    }

    private function stringInput(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_array($value)) {
            $parts = array_filter($value, 'is_string');

            return [] === $parts ? null : implode("\n\n", $parts);
        }

        return null;
    }
}
