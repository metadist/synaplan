<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Provider\OllamaProvider;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Is a concrete model actually downloaded on the Ollama server?
 *
 * {@see OllamaProvider::isAvailable()} only proves the server answers, and a
 * stock install has a reachable Ollama holding nothing but the embedding model.
 * Anything that may route work at a local model therefore has to ask about the
 * concrete model — and everyone has to get the same answer: readiness reporting
 * and model resolution disagreeing about the same server is exactly what
 * produces a chat that fails while the UI insists a provider is connected.
 */
final readonly class OllamaModelInventory
{
    private const CACHE_PREFIX = 'ollama_model_pulled.';

    /**
     * Matches the lifetime of the readiness snapshot, the other cached view of
     * "what can serve a request right now".
     */
    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private ProviderRegistry $providerRegistry,
        private CacheItemPoolInterface $cache,
    ) {
    }

    /**
     * Cached because the underlying probe is an uncached HTTP call per lookup,
     * while model resolution runs several times per message.
     */
    public function isPulled(string $model): bool
    {
        $model = strtolower(trim($model));
        if ('' === $model) {
            return false;
        }

        // Ollama names carry the characters PSR-6 reserves for its own use
        // ("llama3:latest"), so the name is hashed instead of embedded.
        $item = $this->cache->getItem(self::CACHE_PREFIX.hash('xxh128', $model));

        if ($item->isHit()) {
            return (bool) $item->get();
        }

        $provider = $this->ollamaProvider();
        $pulled = null !== $provider && $provider->hasModel($model);

        $item->set($pulled);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $pulled;
    }

    private function ollamaProvider(): ?OllamaProvider
    {
        foreach ($this->providerRegistry->getUniqueProviders() as $provider) {
            if ($provider instanceof OllamaProvider) {
                return $provider;
            }
        }

        return null;
    }
}
