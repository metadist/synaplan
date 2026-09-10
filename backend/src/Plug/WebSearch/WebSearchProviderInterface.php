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

    /**
     * Cheap config check (key / URL present). Used on the chat search path.
     */
    public function health(): PlugHealth;

    /**
     * Live reachability / credential check. Admin badges use this, not {@see health()}.
     */
    public function probe(): PlugHealth;
}
