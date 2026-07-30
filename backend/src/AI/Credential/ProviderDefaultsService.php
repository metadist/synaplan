<?php

declare(strict_types=1);

namespace App\AI\Credential;

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
 */
final readonly class ProviderDefaultsService
{
    /**
     * Per-provider recommended bindings, capability => catalog key.
     *
     * MAIN = flagship chat tier (CHAT, TOOLS, ANALYZE), FAST = cheap/fast tier
     * (SORT, PLAN, SUMMARIZE). MEM/PIC2TEXT/SOUND2TEXT only where the catalog
     * has a matching entry for the provider.
     *
     * @var array<string, array<string, string>>
     */
    private const PROVIDER_DEFAULTS = [
        'groq' => [
            'CHAT' => 'groq:llama-3.3-70b-versatile:chat',
            'TOOLS' => 'groq:llama-3.3-70b-versatile:chat',
            'ANALYZE' => 'groq:llama-3.3-70b-versatile:chat',
            'SORT' => 'groq:openai/gpt-oss-120b:chat',
            'PLAN' => 'groq:openai/gpt-oss-120b:chat',
            'SUMMARIZE' => 'groq:openai/gpt-oss-120b:chat',
            'MEM' => 'groq:openai/gpt-oss-120b:mem',
            'PIC2TEXT' => 'groq:meta-llama/llama-4-scout-17b-16e-instruct:pic2text',
            'SOUND2TEXT' => 'groq:whisper-large-v3:sound2text',
        ],
        'openai' => [
            'CHAT' => 'openai:gpt-5.5:chat',
            'TOOLS' => 'openai:gpt-5.5:chat',
            'ANALYZE' => 'openai:gpt-5.5:chat',
            'SORT' => 'openai:gpt-5.4-mini:chat',
            'PLAN' => 'openai:gpt-5.4-mini:chat',
            'SUMMARIZE' => 'openai:gpt-5.4-mini:chat',
            'PIC2TEXT' => 'openai:gpt-5.5:pic2text',
            'SOUND2TEXT' => 'openai:whisper-1:sound2text',
        ],
        'anthropic' => [
            'CHAT' => 'anthropic:claude-sonnet-5:chat',
            'TOOLS' => 'anthropic:claude-sonnet-5:chat',
            'ANALYZE' => 'anthropic:claude-sonnet-5:chat',
            'SORT' => 'anthropic:claude-haiku-4-5-20251001:chat',
            'PLAN' => 'anthropic:claude-haiku-4-5-20251001:chat',
            'SUMMARIZE' => 'anthropic:claude-haiku-4-5-20251001:chat',
            'MEM' => 'anthropic:claude-sonnet-5:mem',
            'PIC2TEXT' => 'anthropic:claude-sonnet-5:pic2text',
        ],
        'google' => [
            'CHAT' => 'google:gemini-3.5-flash:chat',
            'TOOLS' => 'google:gemini-3.5-flash:chat',
            'ANALYZE' => 'google:gemini-3.5-flash:chat',
            'SORT' => 'google:gemini-3.1-flash-lite:chat',
            'PLAN' => 'google:gemini-3.1-flash-lite:chat',
            'SUMMARIZE' => 'google:gemini-3.1-flash-lite:chat',
            'PIC2TEXT' => 'google:gemini-3.5-flash:pic2text',
        ],
        'mistral' => [
            'CHAT' => 'mistral:mistral-medium-latest:chat',
            'TOOLS' => 'mistral:mistral-medium-latest:chat',
            'ANALYZE' => 'mistral:mistral-medium-latest:chat',
            'SORT' => 'mistral:mistral-medium-latest:chat',
            'PLAN' => 'mistral:mistral-medium-latest:chat',
            'SUMMARIZE' => 'mistral:mistral-medium-latest:chat',
            'PIC2TEXT' => 'mistral:mistral-medium-latest:pic2text',
            'SOUND2TEXT' => 'mistral:voxtral-mini-latest:sound2text',
        ],
        'trustedtokens' => [
            'CHAT' => 'trustedtokens:zai-org/GLM-5.2:chat',
            'TOOLS' => 'trustedtokens:zai-org/GLM-5.2:chat',
            'ANALYZE' => 'trustedtokens:zai-org/GLM-5.2:chat',
            'SORT' => 'trustedtokens:openai/gpt-oss-120b:chat',
            'PLAN' => 'trustedtokens:openai/gpt-oss-120b:chat',
            'SUMMARIZE' => 'trustedtokens:openai/gpt-oss-120b:chat',
            'PIC2TEXT' => 'trustedtokens:Qwen/Qwen3.6-35B-A3B-FP8:pic2text',
        ],
        // NB: colons inside a catalog providerId are normalized to dashes by
        // ModelCatalog::modelKey() — hence "Kimi-K2.6-deepinfra", not ":deepinfra".
        'huggingface' => [
            'CHAT' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
            'TOOLS' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
            'ANALYZE' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
            'SORT' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
            'PLAN' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
            'SUMMARIZE' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:chat',
            'PIC2TEXT' => 'huggingface:moonshotai/Kimi-K2.6-deepinfra:pic2text',
        ],
        'xai' => [
            'CHAT' => 'xai:grok-4.5:chat',
            'TOOLS' => 'xai:grok-4.5:chat',
            'ANALYZE' => 'xai:grok-4.5:chat',
            'SORT' => 'xai:grok-4.5:chat',
            'PLAN' => 'xai:grok-4.5:chat',
            'SUMMARIZE' => 'xai:grok-4.5:chat',
            'PIC2TEXT' => 'xai:grok-4.5:pic2text',
            'SOUND2TEXT' => 'xai:grok-stt:sound2text',
        ],
        // Local Ollama — last resort when a chat-capable model is already present.
        // providerId "gpt-oss:120b" normalises to "gpt-oss-120b" in ModelCatalog keys.
        'ollama' => [
            'CHAT' => 'ollama:gpt-oss-120b:chat',
            'TOOLS' => 'ollama:gpt-oss-120b:chat',
            'ANALYZE' => 'ollama:gpt-oss-120b:chat',
            'SORT' => 'ollama:gpt-oss-120b:chat',
            'PLAN' => 'ollama:gpt-oss-120b:chat',
            'SUMMARIZE' => 'ollama:gpt-oss-120b:chat',
            'MEM' => 'ollama:gpt-oss-120b:mem',
        ],
    ];

    /**
     * Preference order for automatic first-run default selection.
     * Cloud free-tier / fast providers first; local Ollama last.
     *
     * @var list<string>
     */
    public const PREFERENCE_ORDER = [
        'groq',
        'openai',
        'google',
        'mistral',
        'anthropic',
        'trustedtokens',
        'huggingface',
        'xai',
        'ollama',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $logger,
    ) {
    }

    public static function supports(string $provider): bool
    {
        return isset(self::PROVIDER_DEFAULTS[strtolower($provider)]);
    }

    /**
     * If the current global default chat provider is unavailable, pick the
     * first available provider in {@see self::PREFERENCE_ORDER} and apply its
     * recommended defaults. No-op when chat is already ready.
     *
     * @param array<string, bool> $availabilityByName lowercase provider name => available
     *
     * @return string|null the provider that was applied, or null when unchanged
     */
    public function autoApplyBestAvailable(array $availabilityByName): ?string
    {
        $current = strtolower((string) ($this->configRepository->getValue(0, 'ai', 'default_chat_provider') ?? ''));
        if ('' !== $current && ($availabilityByName[$current] ?? false)) {
            return null;
        }

        foreach (self::PREFERENCE_ORDER as $provider) {
            if (!($availabilityByName[$provider] ?? false)) {
                continue;
            }
            if (!self::supports($provider)) {
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
        $mapping = self::PROVIDER_DEFAULTS[$provider] ?? null;
        if (null === $mapping) {
            throw new \InvalidArgumentException(sprintf('No recommended defaults for provider "%s". Supported: %s.', $provider, implode(', ', array_keys(self::PROVIDER_DEFAULTS))));
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
