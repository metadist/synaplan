<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tagged `app.plug.web_search` adapters. `active()` is the first healthy
 * configured provider; `search()` tries that key then a one-shot fallback.
 */
final class WebSearchRegistry
{
    /** @var array<string, WebSearchProviderInterface> */
    private array $byKey = [];

    /**
     * @param iterable<WebSearchProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.plug.web_search')]
        iterable $providers,
        private readonly PlugConfigService $config,
        private readonly WebSearchFallbackMetrics $fallbackMetrics = new WebSearchFallbackMetrics(new \Psr\Log\NullLogger()),
    ) {
        foreach ($providers as $provider) {
            $this->byKey[$provider->key()] = $provider;
        }
    }

    /**
     * @return list<WebSearchProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function byKey(string $key): ?WebSearchProviderInterface
    {
        return $this->byKey[$key] ?? null;
    }

    /**
     * @return list<PlugDescriptor>
     */
    public function descriptors(): array
    {
        $out = [];
        foreach ($this->byKey as $provider) {
            $out[] = $provider->descriptor();
        }

        return $out;
    }

    public function active(?int $userId = null): ?WebSearchProviderInterface
    {
        $key = $this->config->webSearchProvider($userId);
        $provider = $this->byKey[$key] ?? null;
        if (null !== $provider && $provider->health()->available) {
            return $provider;
        }

        return $this->fallback();
    }

    public function fallback(): ?WebSearchProviderInterface
    {
        $key = $this->config->webSearchFallback();
        if ('' === $key) {
            return null;
        }
        $provider = $this->byKey[$key] ?? null;
        if (null !== $provider && $provider->health()->available) {
            return $provider;
        }

        return null;
    }

    /**
     * Run the configured provider, then a different fallback once.
     * Failures become an empty set — callers never see the exception (C7).
     */
    public function search(WebSearchQuery $query, ?int $userId = null): SearchResultSet
    {
        $activeKey = $this->config->webSearchProvider($userId);
        $primary = $this->byKey[$activeKey] ?? null;
        $primarySet = $this->trySearch($primary, $query, $activeKey);
        if (null !== $primarySet) {
            return $primarySet;
        }

        $fallbackKey = $this->config->webSearchFallback();
        if ('' === $fallbackKey || $fallbackKey === $activeKey) {
            return SearchResultSet::empty($query->query, ['provider' => $activeKey]);
        }

        $fallback = $this->byKey[$fallbackKey] ?? null;
        $fallbackSet = $this->trySearch($fallback, $query, $fallbackKey);
        if (null !== $fallbackSet) {
            $this->fallbackMetrics->increment($activeKey, $fallbackKey);

            return $fallbackSet->withMeta(['fellBackFrom' => $activeKey]);
        }

        return SearchResultSet::empty($query->query, [
            'provider' => $activeKey,
            'fellBackFrom' => $activeKey,
        ]);
    }

    public function fallbackMetrics(): WebSearchFallbackMetrics
    {
        return $this->fallbackMetrics;
    }

    private function trySearch(?WebSearchProviderInterface $provider, WebSearchQuery $query, string $key): ?SearchResultSet
    {
        if (null === $provider || !$provider->health()->available) {
            return null;
        }

        $started = hrtime(true);
        try {
            $set = $provider->search($query);
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

            return $set->withMeta([
                'provider' => $key,
                'latencyMs' => $latencyMs,
            ]);
        } catch (\Throwable) {
            return null;
        }
    }
}
