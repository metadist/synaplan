<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\AI\Provider\OllamaProvider;
use App\AI\Service\ProviderRegistry;
use App\Repository\ModelRepository;
use App\Service\ModelConfigService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Answers "can this install chat right now?" — the single source of truth for
 * the first-run setup banner, the model-availability endpoint and the
 * auto-repair command.
 *
 * Reads are cached briefly: probing every provider means one HTTP call to Ollama
 * plus a decrypt per cloud provider, and the frontend polls the runtime config.
 * Writes live in {@see repairDefaultsIfBroken()} and are never triggered by a
 * read path — a GET must not reconfigure the install.
 */
final class ChatReadinessService
{
    private const CACHE_KEY = 'provider_availability.snapshot';
    private const CACHE_TTL_SECONDS = 30;

    public function __construct(
        private readonly ProviderRegistry $providerRegistry,
        private readonly ProviderDefaultsService $defaults,
        private readonly ModelConfigService $modelConfigService,
        private readonly ModelRepository $modelRepository,
        private readonly CacheItemPoolInterface $cache,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Lowercase provider name => usable right now.
     *
     * The TestProvider is included: in the test/E2E environment it serves the
     * default chat model, and a missing entry would read as "the default is
     * broken". Callers that show this to users filter it out themselves.
     *
     * @return array<string, bool>
     */
    public function providerAvailability(bool $fresh = false): array
    {
        return $this->snapshot($fresh)['available'];
    }

    /**
     * Display names of unavailable providers, for the user-facing hint. Excludes
     * the TestProvider, which is an internal fixture.
     *
     * @return list<string>
     */
    public function unavailableProviderNames(bool $fresh = false): array
    {
        $snapshot = $this->snapshot($fresh);

        $names = [];
        foreach ($snapshot['available'] as $name => $available) {
            if (!$available && 'test' !== $name) {
                $names[] = $snapshot['displayNames'][$name] ?? $name;
            }
        }

        return $names;
    }

    /**
     * The provider serving the GLOBAL default chat model, or null when the
     * binding does not resolve (fresh DB, retired model).
     */
    public function defaultChatService(): ?string
    {
        $bid = $this->modelConfigService->getDefaultModel('CHAT');
        if (null === $bid) {
            return null;
        }

        $model = $this->modelRepository->find($bid);

        return null !== $model ? strtolower($model->getService()) : null;
    }

    /**
     * Whether a plain chat message can be answered right now: the provider
     * behind the global default chat model must be usable. Falls back to "any
     * chat-capable provider is usable" only when the binding does not resolve.
     *
     * @param array<string, bool>|null $availability defaults to the cached snapshot
     */
    public function isChatReady(?array $availability = null, ?string $defaultChatService = null): bool
    {
        $availability ??= $this->providerAvailability();
        $defaultChatService ??= $this->defaultChatService();

        if (null !== $defaultChatService && isset($availability[$defaultChatService])) {
            return $availability[$defaultChatService];
        }

        foreach ($this->providerRegistry->getAvailableProviders('chat', false) as $name) {
            if ($availability[strtolower($name)] ?? false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a concrete model is pulled on the local Ollama server.
     *
     * OllamaProvider::isAvailable() only proves the server answers — a stock
     * install has a reachable Ollama holding nothing but the embedding model, so
     * anything that may route work there must check the concrete model.
     */
    public function isOllamaModelPulled(string $model): bool
    {
        $provider = $this->ollamaProvider();

        return null !== $provider && $provider->hasModel($model);
    }

    /**
     * WRITE path — repair a default that points at a cloud provider without a
     * key, so a fresh install can chat without hunting for the right dropdown.
     * Intentionally only reachable from an explicit action (console command at
     * container start, or an admin saving a key), never from a request read.
     *
     * @return string|null the provider whose defaults were applied, or null when nothing changed
     */
    public function repairDefaultsIfBroken(): ?string
    {
        $availability = $this->providerAvailability(fresh: true);
        $defaultChatService = $this->defaultChatService();

        if ($this->isChatReady($availability, $defaultChatService)) {
            return null;
        }

        $applied = $this->defaults->autoApplyBestAvailable($availability, $defaultChatService);
        if (null !== $applied) {
            $this->invalidate();
        }

        return $applied;
    }

    /**
     * Drop the cached snapshot — call after a key was saved or removed so the
     * setup banner reacts to the change immediately.
     */
    public function invalidate(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
    }

    /**
     * @return array{available: array<string, bool>, displayNames: array<string, string>}
     */
    private function snapshot(bool $fresh): array
    {
        $item = $this->cache->getItem(self::CACHE_KEY);
        if (!$fresh && $item->isHit()) {
            /** @var array{available: array<string, bool>, displayNames: array<string, string>} $cached */
            $cached = $item->get();

            return $cached;
        }

        $available = [];
        $displayNames = [];
        foreach ($this->providerRegistry->getUniqueProviders() as $name => $provider) {
            $key = strtolower((string) $name);
            $available[$key] = $provider->isAvailable();
            $displayNames[$key] = $provider->getDisplayName();
        }

        // Ollama answers as soon as the server is up, even with zero models
        // pulled. Treating that as "available" would route chat at a model
        // nobody downloaded and falsely hide the setup banner.
        if (($available['ollama'] ?? false) && !$this->recommendedOllamaChatModelPulled()) {
            $available['ollama'] = false;
        }

        $snapshot = ['available' => $available, 'displayNames' => $displayNames];

        $item->set($snapshot);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $snapshot;
    }

    /**
     * Is the model that the recommended Ollama defaults bind CHAT to actually
     * downloaded? The compose stack only pulls it when ENABLE_LOCAL_GPT_OSS=true.
     */
    private function recommendedOllamaChatModelPulled(): bool
    {
        try {
            $bid = $this->defaults->getRecommendedDefaults('ollama')['CHAT'] ?? null;
        } catch (\Throwable $e) {
            $this->logger->warning('Cannot resolve the recommended Ollama chat model', ['error' => $e->getMessage()]);

            return false;
        }
        if (null === $bid) {
            return false;
        }

        $model = $this->modelRepository->find($bid);

        return null !== $model && $this->isOllamaModelPulled($model->getProviderId());
    }

    private function ollamaProvider(): ?OllamaProvider
    {
        foreach ($this->providerRegistry->getUniqueProviders() as $name => $provider) {
            if ('ollama' === strtolower((string) $name) && $provider instanceof OllamaProvider) {
                return $provider;
            }
        }

        return null;
    }
}
