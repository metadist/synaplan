<?php

declare(strict_types=1);

namespace App\Plug\Rerank;

use App\Plug\PlugConfigService;
use App\Plug\PlugDescriptor;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Tagged `app.plug.rerank` adapters. `active()` is null while
 * PLUGS.RERANK.ENABLED is 0 (S1 default — eval-gated, S4).
 */
final class RerankRegistry
{
    /** @var array<string, RerankProviderInterface> */
    private array $byKey = [];

    /**
     * @param iterable<RerankProviderInterface> $providers
     */
    public function __construct(
        #[AutowireIterator('app.plug.rerank')]
        iterable $providers,
        private readonly PlugConfigService $config,
    ) {
        foreach ($providers as $provider) {
            $this->byKey[$provider->key()] = $provider;
        }
    }

    /**
     * @return list<RerankProviderInterface>
     */
    public function all(): array
    {
        return array_values($this->byKey);
    }

    public function byKey(string $key): ?RerankProviderInterface
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

    public function active(): ?RerankProviderInterface
    {
        if (!$this->config->isRerankEnabled()) {
            return null;
        }

        foreach ($this->byKey as $provider) {
            if ($provider->health()->available) {
                return $provider;
            }
        }

        return null;
    }
}
