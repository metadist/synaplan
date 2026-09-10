<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugHealth;

/**
 * Admin badge health: live probe for SearXNG and the selected providers,
 * "not verified" for an unused key, config reason when nothing is stored.
 */
final readonly class WebSearchAdminHealth
{
    public function __construct(
        private WebSearchHealthCache $cache,
    ) {
    }

    public function forAdapter(WebSearchProviderInterface $adapter, string $active, string $fallback): PlugHealth
    {
        $configured = $adapter->health();
        if (!$configured->available) {
            return $configured;
        }

        $key = $adapter->key();
        if ('searxng' !== $key && $key !== $active && $key !== $fallback) {
            return PlugHealth::unavailable('Key stored — not verified');
        }

        return $this->cache->remember($key, $adapter->probe(...));
    }

    public function remember(string $key, PlugHealth $health): PlugHealth
    {
        return $this->cache->put($key, $health);
    }
}
