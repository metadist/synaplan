<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\AI\Service\OllamaModelInventory;
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
        private readonly OllamaModelInventory $ollamaModelInventory,
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
     * The provider serving the default chat model — the per-user override when
     * a user id is given (mirroring the lookup the chat pipeline itself uses),
     * otherwise the GLOBAL default. Null when the binding does not resolve
     * (fresh DB, retired model).
     */
    public function defaultChatService(?int $userId = null): ?string
    {
        $bid = $this->modelConfigService->getDefaultModel('CHAT', $userId);
        if (null === $bid) {
            return null;
        }

        $model = $this->modelRepository->find($bid);

        return null !== $model ? strtolower($model->getService()) : null;
    }

    /**
     * Whether a plain chat message can be answered right now: the provider
     * behind the effective default chat model must be usable. Pass the user id
     * so a per-user model override is honoured — the chat pipeline resolves
     * the model per user, and readiness must measure the same thing, or a user
     * whose chat works fine is shown a "no AI provider connected" banner.
     * Falls back to "any chat-capable provider is usable" only when the
     * binding does not resolve.
     *
     * @param array<string, bool>|null $availability defaults to the cached snapshot
     */
    public function isChatReady(?array $availability = null, ?string $defaultChatService = null, ?int $userId = null): bool
    {
        $availability ??= $this->providerAvailability();
        $defaultChatService ??= $this->defaultChatService($userId);

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
     */
    public function isOllamaModelPulled(string $model): bool
    {
        return $this->ollamaModelInventory->isPulled($model);
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
     *
     * Model resolution keeps its own snapshot of which providers have
     * credentials, for the same reason and with the same lifetime. Clearing it
     * from here keeps "a key changed" a single call for every caller instead of
     * something each one has to remember twice.
     */
    public function invalidate(): void
    {
        $this->cache->deleteItem(self::CACHE_KEY);
        $this->modelConfigService->invalidateUsableProviders();
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
        if (($available['ollama'] ?? false) && !$this->ollamaChatModelPulled()) {
            $available['ollama'] = false;
        }

        $snapshot = ['available' => $available, 'displayNames' => $displayNames];

        $item->set($snapshot);
        $item->expiresAfter(self::CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $snapshot;
    }

    /**
     * Is the Ollama chat model this install would actually use pulled? When
     * the configured GLOBAL default chat model is an Ollama model, that exact
     * model is checked — an operator who deliberately bound chat to a pulled
     * local model must not be flagged "not ready" just because the recommended
     * model is absent. Otherwise falls back to the recommended binding.
     */
    private function ollamaChatModelPulled(): bool
    {
        $bid = $this->modelConfigService->getDefaultModel('CHAT');
        if (null !== $bid) {
            $model = $this->modelRepository->find($bid);
            if (null !== $model && 'ollama' === strtolower($model->getService())) {
                return $this->isOllamaModelPulled($model->getProviderId());
            }
        }

        return $this->recommendedOllamaChatModelPulled();
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
}
