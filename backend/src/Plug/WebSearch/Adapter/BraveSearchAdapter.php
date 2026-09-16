<?php

declare(strict_types=1);

namespace App\Plug\WebSearch\Adapter;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchLiveProbeInterface;
use App\Plug\WebSearch\WebSearchProbe;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;
use App\Service\Search\BraveSearchService;

/**
 * Wraps {@see BraveSearchService}, which stays the HTTP client.
 * `toLegacyArray()` / `formatForAi()` are byte-identical with today's output.
 *
 * @internal
 */
final readonly class BraveSearchAdapter implements WebSearchProviderInterface, WebSearchLiveProbeInterface
{
    public function __construct(
        private BraveSearchService $braveSearch,
    ) {
    }

    public function key(): string
    {
        return 'brave';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(
            'brave',
            'Brave Search',
            'https://api-dashboard.search.brave.com/app/documentation/web-search/get-started',
            ['BRAVE_SEARCH_API_KEY', 'BRAVE_SEARCH_ENABLED'],
            'US cloud',
        );
    }

    public function capabilities(): WebSearchCapabilities
    {
        return WebSearchCapabilities::brave();
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        $legacy = $this->braveSearch->search($query->query, $query->options);

        return SearchResultSet::fromLegacyArray($legacy);
    }

    public function health(): PlugHealth
    {
        return $this->braveSearch->isEnabled()
            ? PlugHealth::available()
            : PlugHealth::unavailable('Brave Search is not enabled or has no API key');
    }

    public function probe(): PlugHealth
    {
        return WebSearchProbe::run(
            $this->braveSearch->isEnabled(),
            'Brave Search is not enabled or has no API key',
            function (): void {
                $this->braveSearch->search('synaplan', ['count' => 1]);
            },
        );
    }
}
