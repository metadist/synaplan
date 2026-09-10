<?php

declare(strict_types=1);

namespace Plugin\UndeclaredSearch\Plug;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;
use App\Plug\WebSearch\SearchResultSet;
use App\Plug\WebSearch\WebSearchCapabilities;
use App\Plug\WebSearch\WebSearchProviderInterface;
use App\Plug\WebSearch\WebSearchQuery;

/**
 * Test-only adapter whose manifest does NOT declare it in provides.plugs.
 * Booting a kernel against this fixture must fail compilation.
 */
final readonly class UndeclaredSearchAdapter implements WebSearchProviderInterface
{
    public function key(): string
    {
        return 'undeclared_search';
    }

    public function descriptor(): PlugDescriptor
    {
        return new PlugDescriptor('undeclared_search', 'Undeclared', 'https://example.test/', [], 'self-hosted', 'undeclared_search');
    }

    public function capabilities(): WebSearchCapabilities
    {
        return new WebSearchCapabilities(false, false, false, false, false, false);
    }

    public function search(WebSearchQuery $query): SearchResultSet
    {
        return SearchResultSet::empty($query->query);
    }

    public function health(): PlugHealth
    {
        return PlugHealth::available();
    }

    public function probe(): PlugHealth
    {
        return $this->health();
    }
}
