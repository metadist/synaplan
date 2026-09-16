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
     * Must never throw or perform a paid / networked probe — see
     * {@see WebSearchLiveProbeInterface} for admin reachability.
     */
    public function health(): PlugHealth;
}
