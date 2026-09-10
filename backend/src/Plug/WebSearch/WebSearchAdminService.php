<?php

declare(strict_types=1);

namespace App\Plug\WebSearch;

use App\AI\Credential\ProviderKeyStore;
use App\Plug\PlugConfigService;
use App\Plug\PlugHealth;
use App\Plug\PlugKeyStore;
use App\Service\Search\BraveSearchService;

/**
 * Status, selection and Test query for the admin Web search tab.
 */
final readonly class WebSearchAdminService
{
    public function __construct(
        private WebSearchRegistry $registry,
        private PlugConfigService $plugConfig,
        private PlugKeyStore $plugKeys,
        private ProviderKeyStore $providerKeys,
        private BraveSearchService $braveSearch,
        private WebSearchAdminHealth $adminHealth,
    ) {
    }

    /**
     * @return array{
     *     providers: list<array<string, mixed>>,
     *     active: string,
     *     fallback: string,
     *     userOverrideAllowed: bool
     * }
     */
    public function status(): array
    {
        $active = $this->plugConfig->webSearchProvider(null);
        $fallback = $this->plugConfig->webSearchFallback();
        $providers = [];
        foreach ($this->registry->all() as $adapter) {
            $descriptor = $adapter->descriptor();
            $health = $this->adminHealth->forAdapter($adapter, $active, $fallback);
            $providers[] = [
                'key' => $adapter->key(),
                'label' => $descriptor->label,
                'docsUrl' => $descriptor->docsUrl,
                'sovereignty' => $descriptor->sovereignty,
                'pluginId' => $descriptor->pluginId,
                'capabilities' => $adapter->capabilities()->toArray(),
                'health' => [
                    'available' => $health->available,
                    'reason' => $health->reason,
                ],
                'keyStatus' => $this->keyStatus($adapter->key()),
            ];
        }

        return [
            'providers' => $providers,
            'active' => $active,
            'fallback' => $fallback,
            'userOverrideAllowed' => $this->plugConfig->isWebSearchUserOverrideAllowed(),
        ];
    }

    /**
     * @return array{
     *     providers: list<array<string, mixed>>,
     *     active: string,
     *     fallback: string,
     *     userOverrideAllowed: bool
     * }
     */
    public function save(string $active, string $fallback, bool $userOverrideAllowed): array
    {
        $this->plugConfig->setWebSearch($active, $fallback, $userOverrideAllowed);

        return $this->status();
    }

    /**
     * @return array{results: list<array{title: string, url: string}>, answer: ?string, latencyMs: int, error: ?string}
     */
    public function test(string $provider, string $query): array
    {
        $key = $this->plugConfig->requireKnownProvider($provider);
        $adapter = $this->registry->byKey($key);
        if (null === $adapter) {
            throw new \InvalidArgumentException('Unknown web search provider: '.$key);
        }

        $started = hrtime(true);
        try {
            $set = $adapter->search(new WebSearchQuery($query));
            $this->adminHealth->remember($key, PlugHealth::available());
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $results = [];
            foreach (array_slice($set->results, 0, 5) as $row) {
                $results[] = [
                    'title' => \is_string($row['title'] ?? null) ? $row['title'] : '',
                    'url' => \is_string($row['url'] ?? null) ? $row['url'] : '',
                ];
            }

            return [
                'results' => $results,
                'answer' => $set->answer?->text,
                'latencyMs' => $latencyMs,
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->adminHealth->remember($key, PlugHealth::unavailable($e->getMessage()));

            return [
                'results' => [],
                'answer' => null,
                'latencyMs' => (int) ((hrtime(true) - $started) / 1_000_000),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array{configured: bool, source: 'db'|'env'|'none', origin: ?string, maskedKey: string}
     */
    public function saveKey(string $provider, string $key): array
    {
        $normalized = strtolower(trim($provider));
        if ($this->plugKeys->supports($normalized)) {
            $this->plugKeys->saveKey($normalized, $key);

            return $this->plugKeys->getStatus($normalized);
        }
        if ('perplexity' === $normalized) {
            $this->providerKeys->saveKey('perplexity', $key);

            return $this->providerKeys->getStatus('perplexity');
        }

        throw new \InvalidArgumentException('Unknown plug key provider: '.$normalized);
    }

    /**
     * @return array{configured: bool, source: 'db'|'env'|'none', origin: ?string, maskedKey: string}
     */
    public function deleteKey(string $provider): array
    {
        $normalized = strtolower(trim($provider));
        if ($this->plugKeys->supports($normalized)) {
            $this->plugKeys->deleteKey($normalized);

            return $this->plugKeys->getStatus($normalized);
        }
        if ('perplexity' === $normalized) {
            $this->providerKeys->deleteKey('perplexity');

            return $this->providerKeys->getStatus('perplexity');
        }

        throw new \InvalidArgumentException('Unknown plug key provider: '.$normalized);
    }

    /**
     * @return array{configured: bool, source: 'db'|'env'|'none', origin: ?string, maskedKey: string}
     */
    private function keyStatus(string $provider): array
    {
        if ($this->plugKeys->supports($provider)) {
            return $this->plugKeys->getStatus($provider);
        }
        if ('perplexity' === $provider) {
            return $this->providerKeys->getStatus('perplexity');
        }
        if ('brave' === $provider) {
            return [
                'configured' => $this->braveSearch->isEnabled(),
                'source' => $this->braveSearch->isEnabled() ? 'env' : 'none',
                'origin' => null,
                'maskedKey' => '',
            ];
        }
        if ('searxng' === $provider) {
            $adapter = $this->registry->byKey('searxng');
            $configured = $adapter?->health()->available ?? false;

            return [
                'configured' => $configured,
                'source' => $configured ? 'env' : 'none',
                'origin' => null,
                'maskedKey' => '',
            ];
        }

        return ['configured' => false, 'source' => 'none', 'origin' => null, 'maskedKey' => ''];
    }
}
