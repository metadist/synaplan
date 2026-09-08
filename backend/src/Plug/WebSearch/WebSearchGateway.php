<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

/**
 * Caller-facing facade with the three methods {@see \App\Service\Search\BraveSearchService}
 * used to expose. Routes through {@see WebSearchRegistry::active()} so S3 can
 * add SearXNG without touching MessageProcessor again.
 */
final readonly class WebSearchGateway
{
    public function __construct(
        private ?WebSearchRegistry $registry = null,
        private ?WebSearchProviderInterface $override = null,
    ) {
    }

    /**
     * Test helper: wrap a single provider (usually a BraveSearchAdapter over a mock).
     * Production always receives {@see WebSearchRegistry}; `$override` is bound to null.
     */
    public static function forProvider(WebSearchProviderInterface $provider): self
    {
        return new self(null, $provider);
    }

    public function isEnabled(?int $userId = null): bool
    {
        $active = $this->resolve($userId);

        return $active?->health()->available ?? false;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function search(string $query, array $options = [], ?int $userId = null): array
    {
        $active = $this->resolve($userId);
        if (null === $active) {
            throw new \RuntimeException('Web search is not enabled or configured');
        }

        return $active->search(WebSearchQuery::fromLegacy($query, $options))->toLegacyArray();
    }

    /**
     * @param array<string, mixed> $searchResults
     */
    public function formatResultsForAI(array $searchResults): string
    {
        return SearchResultSet::fromLegacyArray($searchResults)->formatForAi();
    }

    private function resolve(?int $userId): ?WebSearchProviderInterface
    {
        if ($this->override instanceof WebSearchProviderInterface) {
            return $this->override;
        }

        return $this->registry?->active($userId);
    }
}
