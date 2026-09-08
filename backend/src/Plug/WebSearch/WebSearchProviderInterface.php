<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugDescriptor;
use App\Plug\PlugHealth;

/**
 * One web-search adapter (Brave, SearXNG, Tavily, …).
 */
interface WebSearchProviderInterface
{
    public function key(): string;

    public function descriptor(): PlugDescriptor;

    public function capabilities(): WebSearchCapabilities;

    public function search(WebSearchQuery $query): SearchResultSet;

    public function health(): PlugHealth;
}
