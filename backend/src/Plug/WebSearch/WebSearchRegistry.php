<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tagged `app.plug.web_search` adapters. `active()` is the configured
 * provider (Brave today); `fallback()` is empty until S3.
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
}
