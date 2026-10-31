<?php

declare(strict_types=1);

namespace App\AI\Credential;

use App\AI\Catalog\ProviderCatalog;
use App\Model\ModelCatalog;
use App\Repository\ConfigRepository;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Applies the recommended global default-model bindings for one provider —
 * the "make this my AI provider" action of the first-run wizard (and the
 * `app:provider:apply-defaults` console command).
 *
 * Replaces the fragile raw-SQL approach previously used by the install
 * script (`UPDATE BCONFIG SET BVALUE='9' ...`): every binding references the
 * catalog by a stable `service:providerId:tag` key and is resolved through
 * {@see ModelCatalog::findBidByKey} at apply time, so catalog renumbering can
 * never silently repoint installs at the wrong model.
 *
 * Only capabilities the provider actually covers are written; everything else
 * (e.g. VECTORIZE → local bge-m3, media models) keeps its current value.
 *
 * The bindings themselves live on each provider's descriptor
 * ({@see \App\AI\Catalog\ProviderDefaults}), read through the catalog.
 */
final readonly class ProviderDefaultsService
{
    public function __construct(
        private ConfigRepository $configRepository,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
        private ProviderKeyCatalog $keyCatalog,
        private ProviderCatalog $catalog,
    ) {
    }

    /** Does the provider declare recommended bindings? Media and speech keys do not. */
    public function supports(string $provider): bool
    {
        return null !== $this->catalog->find($provider)?->defaults;
    }

    /**
     * Preference order for automatic first-run default selection: cloud
     * free-tier / fast providers first, local Ollama last — the descriptors'
     * `preferenceRank`, ascending. A provider without a rank is never picked.
     *
     * @return list<string>
     */
    public function preferenceOrder(): array
    {
        $ranked = [];
        foreach ($this->catalog->all() as $descriptor) {
            $rank = $descriptor->defaults?->preferenceRank;
            if (null !== $rank) {
                $ranked[$rank] = $descriptor->name;
            }
        }
        ksort($ranked);

        return array_values($ranked);
    }

    /**
     * Repair a default that points at a cloud provider the operator never gave a
     * key for: pick the first available provider in {@see self::preferenceOrder()}
     * and apply its recommended defaults, so chat works without an explicit
     * "Use as default" click on a fresh install.
     *
     * Deliberately narrow — it only ever overrides a **keyed cloud provider
     * without a usable key**. A default served by Ollama, the test provider or
     * any other keyless provider is a deliberate choice and is never rewritten,
     * not even while that provider is briefly unreachable: a single request
     * during an Ollama restart must not permanently repoint the install at a
     * cloud provider.
     *
     * @param array<string, bool> $availabilityByName lowercase provider name => available
     * @param string|null         $defaultChatService provider serving the current global default CHAT model, null when it does not resolve
     *
     * @return string|null the provider that was applied, or null when unchanged
     */
    public function autoApplyBestAvailable(array $availabilityByName, ?string $defaultChatService = null): ?string
    {
        $current = strtolower((string) ($this->configRepository->getValue(0, 'ai', 'default_chat_provider') ?? ''));

        foreach ([$defaultChatService, '' !== $current ? $current : null] as $incumbent) {
            if (null === $incumbent) {
                continue;
            }
            $incumbent = strtolower($incumbent);
            // Keyless provider (ollama, test, self-hosted) => operator's choice.
            if (!$this->keyCatalog->has($incumbent)) {
                return null;
            }
            // Has a usable key => nothing to repair.
            if ($availabilityByName[$incumbent] ?? false) {
                return null;
            }
        }

        foreach ($this->preferenceOrder() as $provider) {
            if (!($availabilityByName[$provider] ?? false)) {
                continue;
            }

            $this->applyGlobalDefaults($provider);
            $this->logger->info('Auto-applied provider defaults because chat was not ready', [
                'provider' => $provider,
                'previous' => $current,
            ]);

            return $provider;
        }

        return null;
    }

    /**
     * The recommended bindings for a provider, resolved to current BIDs.
     * Throws when a catalog key no longer resolves (catalog drift) — a unit
     * test locks every mapping, so this only fires on an inconsistent build.
     *
     * @return array<string, int> capability => BID
     */
    public function getRecommendedDefaults(string $provider): array
    {
        $provider = strtolower($provider);
        $mapping = $this->catalog->find($provider)?->defaults?->bindings;
        if (null === $mapping) {
            throw new \InvalidArgumentException(sprintf('No recommended defaults for provider "%s". Supported: %s.', $provider, implode(', ', $this->providersWithDefaults())));
        }

        $resolved = [];
        foreach ($mapping as $capability => $modelKey) {
            $bid = ModelCatalog::findBidByKey($modelKey);
            if (null === $bid) {
                throw new \RuntimeException(sprintf("ProviderDefaultsService: model key '%s' (provider %s, capability %s) does not resolve to exactly one ModelCatalog entry.", $modelKey, $provider, $capability));
            }
            $resolved[$capability] = $bid;
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private function providersWithDefaults(): array
    {
        $names = [];
        foreach ($this->catalog->all() as $descriptor) {
            if (null !== $descriptor->defaults) {
                $names[] = $descriptor->name;
            }
        }

        return $names;
    }

    /**
     * Write the recommended bindings as the GLOBAL defaults (ownerId = 0) and
     * flip `ai.default_chat_provider`. Explicit admin action — overwrites
     * previous global defaults, never per-user overrides.
     *
     * @return array<string, int> the applied capability => BID map
     */
    public function applyGlobalDefaults(string $provider): array
    {
        $provider = strtolower($provider);
        $defaults = $this->getRecommendedDefaults($provider);

        foreach ($defaults as $capability => $bid) {
            $this->configRepository->setValue(0, 'DEFAULTMODEL', $capability, (string) $bid);
        }
        $this->configRepository->setValue(0, 'ai', 'default_chat_provider', $provider);

        // Same blunt invalidation ModelConfigService::setDefaultModel uses —
        // the model_config pool caches per-user/per-capability lookups.
        $this->cache->clear();

        $this->logger->info('Applied recommended provider defaults', [
            'provider' => $provider,
            'capabilities' => array_keys($defaults),
        ]);

        return $defaults;
    }
}
