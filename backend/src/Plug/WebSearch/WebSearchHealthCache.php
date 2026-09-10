<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugHealth;

/**
 * Process-local TTL cache for live web-search probes (same idea as Docling health).
 */
final class WebSearchHealthCache
{
    private const TTL_SECONDS = 30;

    /** @var array<string, array{health: PlugHealth, at: int}> */
    private array $items = [];

    public function remember(string $key, callable $probe): PlugHealth
    {
        $hit = $this->items[$key] ?? null;
        if (null !== $hit && (time() - $hit['at']) < self::TTL_SECONDS) {
            return $hit['health'];
        }

        $health = $probe();
        if (!$health instanceof PlugHealth) {
            throw new \LogicException('Web search health probe must return PlugHealth');
        }

        return $this->put($key, $health);
    }

    public function put(string $key, PlugHealth $health): PlugHealth
    {
        $this->items[$key] = ['health' => $health, 'at' => time()];

        return $health;
    }
}
