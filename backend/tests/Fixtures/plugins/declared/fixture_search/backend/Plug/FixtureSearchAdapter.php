<?php

declare(strict_types=1);

namespace Plugin\FixtureSearch\Plug;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResult;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * Test-only web-search adapter that proves a plugin adapter is picked up by the
 * registry with zero edits to backend/src. Returns a fixed result, so the
 * integration test can assert the plugin's output flows through the port.
 */
final readonly class FixtureSearchAdapter implements WebSearchProviderInterface
{
    public const KEY = 'fixture_search';

    public function key(): string
    {
        return self::KEY;
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor(self::KEY, 'Fixture Search', 'https://example.test/', [], 'self-hosted', 'fixture_search');
    }

    public function capabilities(): WebSearchCapabilities
    {
        return new WebSearchCapabilities(false, false, false, false, false, false);
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        return SearchResultSet::fromResults($query->query, [
            new SearchResult(title: 'Fixture result', url: 'https://example.test/hit'),
        ], ['provider' => self::KEY, 'total' => 1]);
    }

    public function health(): PlugHealth
    {
        return PlugHealth::available();
    }
}
