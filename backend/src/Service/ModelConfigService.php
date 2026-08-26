<?php

namespace App\Service;

use App\AI\Service\OllamaModelInventory;
use App\AI\Service\ProviderRegistry;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelHealthRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Seed\DefaultModelConfigSeeder;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Resolves which AI model a user should get, from their settings.
 *
 * User-specific defaults live in BCONFIG; the catalog of available models is BMODELS.
 */
final readonly class ModelConfigService
{
    private const USABLE_PROVIDERS_CACHE_KEY = 'model_config.usable_providers';
    private const USABLE_PROVIDERS_CACHE_TTL_SECONDS = 60;

    private const OFFLINE_MODELS_CACHE_KEY = 'model_config.offline_models';
    private const OFFLINE_MODELS_CACHE_TTL_SECONDS = 60;

    /**
     * DEFAULTMODEL capability => BMODELS.BTAG, for the last-resort pick in
     * {@see firstUsableModelForCapability()}. Several capabilities share a tag
     * because they differ in role, not in what the model has to be able to do:
     * SORT/PLAN/SUMMARIZE/TOOLS/ANALYZE are all chat completions.
     *
     * A capability missing here simply has no emergency fallback.
     *
     * @var array<string, string>
     */
    private const CAPABILITY_TAGS = [
        'ANALYZE' => 'chat',
        'CHAT' => 'chat',
        'PLAN' => 'chat',
        'SORT' => 'chat',
        'SUMMARIZE' => 'chat',
        'TOOLS' => 'chat',
        'MEM' => 'mem',
        'EMBEDDING' => 'vectorize',
        'SYNAPSE_VECTORIZE' => 'vectorize',
        'VECTORIZE' => 'vectorize',
        'PIC2TEXT' => 'pic2text',
        'PIC2PIC' => 'text2pic',
        'TEXT2PIC' => 'text2pic',
        'IMG2VID' => 'text2vid',
        'TEXT2VID' => 'text2vid',
        'SOUND2TEXT' => 'sound2text',
        'TEXT2SOUND' => 'text2sound',
    ];

    public function __construct(
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private UserRepository $userRepository,
        private CacheItemPoolInterface $cache,
        private ProviderRegistry $providerRegistry,
        private OllamaModelInventory $ollamaModelInventory,
        private ModelHealthRepository $modelHealthRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Default provider for a user and capability.
     *
     * Order:
     * 1. User-specific config (BCONFIG: BOWNERID=userId, BGROUP='ai', BSETTING='default_chat_provider')
     * 2. Global default config (BOWNERID=0)
     * 3. Smart fallback from the database
     */
    public function getDefaultProvider(?int $userId, string $capability = 'chat'): string
    {
        $cacheKey = "model_config.provider.{$userId}.{$capability}";
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        // 1. User-specific config
        if ($userId) {
            $config = $this->configRepository->findByOwnerGroupAndSetting(
                $userId,
                'ai',
                "default_{$capability}_provider"
            );

            if ($config) {
                $provider = $config->getValue();
                $item->set($provider);
                $item->expiresAfter(300); // 5 minute cache
                $this->cache->save($item);

                return $provider;
            }
        }

        // 2. Global Default (ownerId = 0)
        $config = $this->configRepository->findByOwnerGroupAndSetting(
            0,
            'ai',
            "default_{$capability}_provider"
        );

        if ($config) {
            $provider = $config->getValue();
            $item->set($provider);
            $item->expiresAfter(300);
            $this->cache->save($item);

            return $provider;
        }

        // 3. Smart Fallback: Try to find a real provider from DB
        $fallback = $this->findFallbackProvider($capability);
        $item->set($fallback);
        $item->expiresAfter(60);
        $this->cache->save($item);

        return $fallback;
    }

    /**
     * Find a fallback provider for a capability from the database.
     *
     * Looks for the first active, selectable model with matching tag,
     * but only if the provider is actually available (API key configured).
     *
     * @param string $capability The capability (chat, speech_to_text, etc.)
     *
     * @return string Provider name (lowercase) or 'test' if none found
     */
    private function findFallbackProvider(string $capability): string
    {
        $tagMap = [
            'chat' => 'chat',
            'embedding' => 'vectorize',
            'vision' => 'pic2text',
            'image_generation' => 'text2pic',
            'pic2pic' => 'text2pic',
            'video_generation' => 'text2vid',
            'speech_to_text' => 'sound2text',
            'text_to_speech' => 'text2sound',
            'file_analysis' => 'analyze',
        ];

        $tag = $tagMap[$capability] ?? $capability;

        $availableProviders = array_map(
            'strtolower',
            $this->providerRegistry->getAvailableProviders($capability, false)
        );

        if (empty($availableProviders)) {
            return 'test';
        }

        $models = $this->modelRepository->findByTag($tag, true);

        foreach ($models as $model) {
            $provider = strtolower($model->getService());

            if (in_array($provider, $availableProviders, true)) {
                return $provider;
            }
        }

        return 'test';
    }

    /**
     * Default model for a user, provider and capability (OLD METHOD - DEPRECATED).
     *
     * Order:
     * 1. User-specific config (BCONFIG: 'default_chat_model')
     * 2. BMODELS table (BPROVIDER, BCAPABILITY, BISDEFAULT=1)
     * 3. ENV variable (fallback)
     */
    public function getDefaultModelOld(?int $userId, string $provider, string $capability = 'chat'): ?string
    {
        $cacheKey = "model_config.model.{$userId}.{$provider}.{$capability}";
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        // 1. User-specific config
        if ($userId) {
            $config = $this->configRepository->findByOwnerGroupAndSetting(
                $userId,
                'ai',
                "default_{$capability}_model"
            );

            if ($config) {
                $model = $config->getValue();
                $item->set($model);
                $item->expiresAfter(300);
                $this->cache->save($item);

                return $model;
            }
        }

        // 2. BMODELS table
        $model = $this->modelRepository->findDefaultByProviderAndCapability($provider, $capability);

        if ($model) {
            $modelName = $model->getName();
            $item->set($modelName);
            $item->expiresAfter(300);
            $this->cache->save($item);

            return $modelName;
        }

        // 3. Return null — the provider then uses its own default
        $item->set(null);
        $item->expiresAfter(60);
        $this->cache->save($item);

        return null;
    }

    /**
     * Set a user-specific default provider.
     */
    public function setDefaultProvider(int $userId, string $capability, string $provider): void
    {
        $config = $this->configRepository->findByOwnerGroupAndSetting(
            $userId,
            'ai',
            "default_{$capability}_provider"
        );

        if (!$config) {
            $config = new \App\Entity\Config();
            $config->setOwnerId($userId);
            $config->setGroup('ai');
            $config->setSetting("default_{$capability}_provider");
        }

        $config->setValue($provider);
        $this->configRepository->save($config);

        // Clear Cache
        $this->cache->deleteItem("model_config.provider.{$userId}.{$capability}");
    }

    /**
     * Set a user-specific default model.
     */
    public function setDefaultModel(int $userId, string $capability, string $model): void
    {
        $config = $this->configRepository->findByOwnerGroupAndSetting(
            $userId,
            'ai',
            "default_{$capability}_model"
        );

        if (!$config) {
            $config = new \App\Entity\Config();
            $config->setOwnerId($userId);
            $config->setGroup('ai');
            $config->setSetting("default_{$capability}_model");
        }

        $config->setValue($model);
        $this->configRepository->save($config);

        // Clear Cache
        $cacheKeys = [
            "model_config.model.{$userId}.*.{$capability}",
        ];

        // TODO: Implement cache tag-based invalidation
        $this->cache->clear();
    }

    /**
     * Full AI config for a user.
     */
    public function getUserAiConfig(?int $userId): array
    {
        $visionDefault = $this->resolveVisionDefault($userId);

        return [
            'chat' => [
                'provider' => $this->getDefaultProvider($userId, 'chat'),
                'model' => $this->getDefaultModel('CHAT', $userId),
            ],
            'vision' => [
                'provider' => $visionDefault['provider'],
                'model' => $visionDefault['model_id'],
            ],
            'embedding' => [
                'provider' => $this->getDefaultProvider($userId, 'embedding'),
                'model' => $this->getDefaultModel('EMBEDDING', $userId),
            ],
        ];
    }

    /**
     * Resolve the user's configured Pic→Text default model and provider.
     *
     * The settings UI writes image-recognition defaults to DEFAULTMODEL.PIC2TEXT
     * as a numeric BMODELS id. Return both the DB id for config/debug surfaces
     * and the provider-facing model name for runtime calls.
     *
     * @return array{provider: string, model: ?string, model_id: ?int}
     */
    public function resolveVisionDefault(?int $userId): array
    {
        $visionModelId = $this->getDefaultModel('PIC2TEXT', $userId);
        $visionModelName = null;
        $visionProvider = null;

        if ($visionModelId) {
            $visionProvider = $this->getProviderForModel((int) $visionModelId);
            if (null === $visionProvider) {
                $visionModelId = null;
            } else {
                $visionModelName = $this->getModelName((int) $visionModelId);
            }
        }

        if (null === $visionProvider) {
            $visionProvider = $this->getDefaultProvider($userId, 'vision');
        }

        return [
            'provider' => $visionProvider,
            'model' => $visionModelName,
            'model_id' => $visionModelId,
        ];
    }

    /**
     * Resolve the user's configured Sound→Text default model and provider.
     *
     * The settings UI writes transcription defaults to DEFAULTMODEL.SOUND2TEXT
     * as a numeric BMODELS id. Return both the DB id for config/debug surfaces
     * and the provider-facing model name for runtime calls.
     *
     * Mirrors resolveVisionDefault() so AiFacade::transcribe() can honour the
     * configured row instead of falling through to the legacy
     * ai/default_speech_to_text_provider chain (which the settings UI never
     * writes — see issue #696).
     *
     * @return array{provider: string, model: ?string, model_id: ?int}
     */
    public function resolveSttDefault(?int $userId): array
    {
        $sttModelId = $this->getDefaultModel('SOUND2TEXT', $userId);
        $sttModelName = null;
        $sttProvider = null;

        if ($sttModelId) {
            $sttProvider = $this->getProviderForModel((int) $sttModelId);
            if (null === $sttProvider) {
                // BMODELS row is gone (e.g. catalog reshuffle): drop the stale
                // id so callers fall back to the capability-level provider chain.
                $sttModelId = null;
            } else {
                $sttModelName = $this->getModelName((int) $sttModelId);
            }
        }

        if (null === $sttProvider) {
            $sttProvider = $this->getDefaultProvider($userId, 'speech_to_text');
        }

        return [
            'provider' => $sttProvider,
            'model' => $sttModelName,
            'model_id' => $sttModelId,
        ];
    }

    /**
     * Get default model ID for a specific capability.
     *
     * Priority: User Config > Global Config > live model for the capability.
     * In test env, ConfigFixtures seeds global defaults pointing to TestProvider models.
     *
     * A per-user binding whose provider currently has no credentials is skipped
     * in favour of the global default. {@see resetUserDefaults()} writes the
     * code-recommended bindings when an account is created, without knowing
     * which providers this install can actually reach, while the key-save path
     * only ever repairs the global row. Installs that receive their first API
     * key AFTER the first account exists — every App Store or appliance
     * install, where the operator logs in before configuring anything — would
     * otherwise keep routing that user at a provider they never configured.
     * The override stays stored and takes effect again as soon as its provider
     * has credentials.
     *
     * The global row is checked the same way. It used to be returned unchecked,
     * which is how a whole install kept routing at Groq llama-3.3-70b-versatile
     * (BID 9) after the model was shut down upstream: every message — including
     * the ones anonymous visitors send through the guest path — died on a
     * provider "model_not_found" that no amount of data migration could prevent
     * for the NEXT retirement. When neither binding resolves, a live model of
     * the same capability is picked over failing the request.
     */
    public function getDefaultModel(string $capability, ?int $userId = null): ?int
    {
        $setting = strtoupper($capability);
        $preferred = null;

        // Read lazily: a usable per-user binding must not cost a global lookup.
        foreach ($userId ? [$userId, 0] : [0] as $ownerId) {
            $modelId = $this->readDefaultModel($ownerId, $setting);
            if (null === $modelId) {
                continue;
            }

            if ($this->isModelProviderUsable($modelId)) {
                return $modelId;
            }

            $preferred ??= $modelId;
        }

        $fallback = $this->firstUsableModelForCapability($setting);
        if (null !== $fallback) {
            return $fallback;
        }

        // Nothing usable anywhere — hand back the configured binding rather than
        // null, so an install without a single reachable provider behaves as it
        // always did and the caller can report the model it was meant to use.
        return $preferred;
    }

    /**
     * Best live model for a DEFAULTMODEL capability, used only when no binding
     * resolves. Selectable models win over hidden ones so an emergency pick
     * lands on something the user could have chosen themselves; the MEM tag has
     * no selectable rows at all, which is why the hidden pass exists.
     */
    private function firstUsableModelForCapability(string $setting): ?int
    {
        $tag = self::CAPABILITY_TAGS[$setting] ?? null;
        if (null === $tag) {
            return null;
        }

        // Health is a preference, not a veto. Pass one skips the models the
        // monitor considers dead; pass two accepts them anyway. A wrong health
        // verdict must never be able to leave a capability with no model at
        // all — routing at a suspect model beats routing nowhere, and the
        // status page is there to tell the operator what happened.
        foreach ([true, false] as $healthyOnly) {
            foreach ([true, false] as $selectableOnly) {
                // findByTag() orders by quality DESC, id ASC.
                foreach ($this->modelRepository->findByTag($tag, $selectableOnly) as $model) {
                    if (!$this->isModelUsable($model)) {
                        continue;
                    }
                    if ($healthyOnly && $this->isModelOffline((int) $model->getId())) {
                        continue;
                    }

                    return $model->getId();
                }
            }
        }

        return null;
    }

    /**
     * Has the health monitor seen this model fail permanently?
     *
     * Cached like the usable-provider snapshot and for the same reason: this
     * sits on the model-resolution path, which runs several times per message.
     */
    private function isModelOffline(int $modelId): bool
    {
        return in_array($modelId, $this->offlineModelIds(), true);
    }

    /**
     * @return list<int>
     */
    private function offlineModelIds(): array
    {
        $item = $this->cache->getItem(self::OFFLINE_MODELS_CACHE_KEY);
        if ($item->isHit()) {
            /** @var list<int> $cached */
            $cached = $item->get();

            return $cached;
        }

        try {
            $ids = $this->modelHealthRepository->findOfflineModelIds();
        } catch (\Throwable) {
            // Before the health table exists (fresh install mid-migration) the
            // answer is simply "nothing is known to be offline".
            $ids = [];
        }

        $item->set($ids);
        $item->expiresAfter(self::OFFLINE_MODELS_CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $ids;
    }

    /**
     * Re-resolve a model id that came from somewhere other than the DEFAULTMODEL
     * chain — a widget's `aiModelId`, a prompt's `aiModel` override, an id the
     * caller carried along. Those bindings are stored copies of a BID and go
     * stale exactly like a default does, but they are read AHEAD of the default,
     * so an unchecked one keeps a retired model in play even after a migration
     * repointed BCONFIG.
     *
     * Returns the id unchanged when it can still serve, otherwise the capability
     * default.
     */
    public function resolveUsableModelId(?int $modelId, string $capability, ?int $userId = null): ?int
    {
        if (null === $modelId || $modelId <= 0) {
            return $modelId;
        }

        if (!$this->isModelProviderUsable($modelId)) {
            return $this->getDefaultModel($capability, $userId);
        }

        // The binding still resolves, but the health monitor saw this model
        // fail permanently. Move to the capability default only when that
        // default is not itself under suspicion — swapping one broken model for
        // another just makes the failure harder to explain.
        if ($this->isModelOffline($modelId)) {
            $fallback = $this->getDefaultModel($capability, $userId);
            if (null !== $fallback && $fallback !== $modelId && !$this->isModelOffline($fallback)) {
                $this->logger->info('Routing away from a model reported as unavailable', [
                    'model_id' => $modelId,
                    'fallback_model_id' => $fallback,
                    'capability' => $capability,
                ]);

                return $fallback;
            }
        }

        return $modelId;
    }

    private function readDefaultModel(int $ownerId, string $setting): ?int
    {
        $config = $this->configRepository->findOneBy([
            'ownerId' => $ownerId,
            'group' => 'DEFAULTMODEL',
            'setting' => $setting,
        ]);

        return $config ? (int) $config->getValue() : null;
    }

    /**
     * Can this model actually serve a request right now?
     *
     * Answers the provider-level question ("are there working credentials")
     * rather than "does it serve this capability": the capability is already
     * encoded in the binding, and a per-capability mapping of the DEFAULTMODEL
     * settings onto registry capabilities would be one more thing to keep in
     * sync for no gain.
     *
     * Ollama is the exception that has to be model-aware. Its provider reports
     * itself available as soon as the server answers, which a stock install
     * does while holding nothing but the embedding model — so a provider-level
     * answer would happily route chat at a model nobody downloaded.
     *
     * A binding with no BMODELS row at all is judged by its sign. Negative ids
     * are the catalog's placeholder convention ("let the provider registry
     * decide") and must keep working. A missing POSITIVE id is a model that was
     * deleted or never seeded, and routing it is worse than it looks: the
     * caller ends up with a model id but no provider and no model name, so the
     * registry answers from its own default and the user silently gets a
     * different model than the one configured. Treating it as unusable turns
     * that into a deliberate, logged fallback.
     */
    private function isModelProviderUsable(int $modelId): bool
    {
        $model = $this->modelRepository->find($modelId);
        if (!$model) {
            return $modelId < 0;
        }

        return $this->isModelUsable($model);
    }

    /**
     * Can this concrete row serve a request right now?
     */
    private function isModelUsable(Model $model): bool
    {
        // BACTIVE = 0 is how this codebase records "do not route here anymore" —
        // an operator switching a model off in the admin UI, or a retire
        // migration reacting to a provider shutdown. Handing such a row to the
        // provider produces a hard mid-request error, so it never counts as
        // usable, no matter how healthy the credentials behind it are.
        if (1 !== $model->getActive()) {
            return false;
        }

        $usable = $this->usableProviders();

        // An empty set means "cannot tell" (no providers registered at all),
        // never "nothing works" — falling back on that basis would replace a
        // configured binding with an equally unusable one.
        if ([] === $usable) {
            return true;
        }

        $service = strtolower($model->getService());

        if (!in_array($service, $usable, true)) {
            return false;
        }

        return 'ollama' !== $service || $this->ollamaModelInventory->isPulled($model->getProviderId());
    }

    /**
     * Drop the cached provider snapshot so a key that was just saved or removed
     * takes effect on the very next model resolution. The admin UI promises the
     * change applies without a restart, and a stale snapshot would keep routing
     * at the old provider for up to the cache lifetime.
     */
    public function invalidateUsableProviders(): void
    {
        $this->cache->deleteItem(self::USABLE_PROVIDERS_CACHE_KEY);
    }

    /**
     * Drop the cached health snapshot so a re-check or an operator's override
     * takes effect on the next model resolution instead of after the TTL.
     */
    public function invalidateModelHealth(): void
    {
        $this->cache->deleteItem(self::OFFLINE_MODELS_CACHE_KEY);
    }

    /**
     * Lowercased names of the providers that currently have credentials.
     *
     * Cached briefly because this sits on the model-resolution path, which runs
     * several times per message, while the underlying probe decrypts a stored
     * key per cloud provider and talks HTTP to a local Ollama.
     *
     * @return list<string>
     */
    private function usableProviders(): array
    {
        $item = $this->cache->getItem(self::USABLE_PROVIDERS_CACHE_KEY);

        if ($item->isHit()) {
            /** @var list<string> $cached */
            $cached = $item->get();

            return $cached;
        }

        $usable = [];
        foreach ($this->providerRegistry->getUniqueProviders() as $provider) {
            if ($provider->isAvailable()) {
                $usable[] = strtolower($provider->getName());
            }
        }

        $item->set($usable);
        $item->expiresAfter(self::USABLE_PROVIDERS_CACHE_TTL_SECONDS);
        $this->cache->save($item);

        return $usable;
    }

    /**
     * Get provider + model config for internal/tools tasks (feedback, memories, contradiction checks).
     * Uses DEFAULTMODEL/TOOLS config. Falls back to global CHAT default.
     *
     * @return array{provider: ?string, model: ?string, model_id: ?int}
     */
    public function getToolsModelConfig(): array
    {
        $modelId = $this->getDefaultModel('TOOLS');

        // Fallback to global CHAT default
        if (!$modelId) {
            $modelId = $this->getDefaultModel('CHAT', 0);
        }

        if (!$modelId) {
            return ['provider' => null, 'model' => null, 'model_id' => null];
        }

        return [
            'provider' => $this->getProviderForModel($modelId),
            'model' => $this->getModelName($modelId),
            'model_id' => $modelId,
        ];
    }

    /**
     * Resolve the model that should run memory-related AI calls (auto-extraction
     * from chat messages AND the "New Memory" parse endpoint in the UI).
     *
     * Priority:
     *   1. User-scoped DEFAULTMODEL.MEM   (per-user override, set via the admin UI)
     *   2. Global DEFAULTMODEL.MEM        (the dedicated "Memory extraction model"
     *                                       BMODELS row, BTAG=mem, default points at
     *                                       Groq gpt-oss-120b for ~200 ms TTFT)
     *   3. User-scoped DEFAULTMODEL.CHAT  (legacy fallback — preserved for
     *                                       installations that haven't seeded
     *                                       the MEM tag yet)
     *   4. Global DEFAULTMODEL.CHAT       (last resort)
     *
     * The MEM tag exists so picking a slow/expensive chat model (e.g. Claude
     * Opus 4) for the user-facing answer no longer cascades into the cheaper
     * memory extraction path. Centralising the resolution here keeps the
     * background MemoryExtractionService and the synchronous UserMemoryController
     * parse endpoint in lockstep — see issue #973.
     *
     * @return array{model: ?string, provider: ?string, model_id: ?int}
     */
    public function getMemoryModelConfig(?int $userId = null): array
    {
        // getDefaultModel() already walks user-scope → global, so we only need
        // two outer calls (MEM then CHAT) — not four. Hitting MEM/0 explicitly
        // after MEM/$userId would just repeat the same global lookup.
        $modelId = $this->getDefaultModel('MEM', $userId)
            ?? $this->getDefaultModel('CHAT', $userId);

        if (!$modelId) {
            return ['model' => null, 'provider' => null, 'model_id' => null];
        }

        return [
            'model' => $this->getModelName($modelId),
            'provider' => $this->getProviderForModel($modelId),
            'model_id' => $modelId,
        ];
    }

    /**
     * Resolve the model that condenses long conversations into a rolling summary.
     *
     * Priority:
     *   1. User-scoped DEFAULTMODEL.SUMMARIZE (per-user override, e.g. GPT-OSS-120B)
     *   2. Global DEFAULTMODEL.SUMMARIZE      (operator-configured summary model)
     *   3. User/global DEFAULTMODEL.SORT      (default: reuse the sorting model —
     *                                          cheap + fast, and always seeded)
     *   4. User/global DEFAULTMODEL.CHAT      (last resort)
     *
     * Keeping this next to getMemoryModelConfig()/getToolsModelConfig() means the
     * ConversationSummaryService never hardcodes a model name; operators pick the
     * condensing model in the UI.
     *
     * @return array{model: ?string, provider: ?string, model_id: ?int}
     */
    public function getSummaryModelConfig(?int $userId = null): array
    {
        // Capability key is 'SUMMARIZE' end to end (seeder, ModelCatalog map,
        // ChatRunner). Reading 'SUMMARY' here silently missed the seeded default
        // and always fell through to SORT (#1320).
        $modelId = $this->getDefaultModel('SUMMARIZE', $userId)
            ?? $this->getDefaultModel('SORT', $userId)
            ?? $this->getDefaultModel('CHAT', $userId);

        if (!$modelId) {
            return ['model' => null, 'provider' => null, 'model_id' => null];
        }

        return [
            'model' => $this->getModelName($modelId),
            'provider' => $this->getProviderForModel($modelId),
            'model_id' => $modelId,
        ];
    }

    /**
     * Get provider name for a specific model ID
     * Returns provider name from BMODELS.BSERVICE (e.g., 'Ollama', 'OpenAI').
     */
    public function getProviderForModel(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);

        if (!$model) {
            return null;
        }

        return strtolower($model->getService());
    }

    /**
     * Get model name for AI provider
     * Returns the actual model identifier (BPROVID or BNAME).
     */
    public function getModelName(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);

        if (!$model) {
            return null;
        }

        // Use BPROVID if set, otherwise BNAME
        return $model->getProviderId() ?: $model->getName();
    }

    /**
     * Check if a model supports streaming
     * Returns true by default if not specified (backward compatibility).
     */
    public function supportsStreaming(int $modelId): bool
    {
        $model = $this->modelRepository->find($modelId);

        if (!$model) {
            return true; // Default: assume streaming support
        }

        // Check BJSON for supportsStreaming flag
        $features = $model->getFeatures();
        $json = $model->getJson();

        // Check if supportsStreaming is explicitly set to false
        if (isset($json['supportsStreaming'])) {
            return (bool) $json['supportsStreaming'];
        }

        // Default: true (backward compatibility)
        return true;
    }

    /**
     * New accounts do not receive frozen per-user DEFAULTMODEL rows.
     *
     * Global defaults (and the runtime fallback to the first usable model)
     * apply until the user explicitly picks a model. Copying the seed
     * catalog into every BUSER left air-gapped installs pointed at
     * Claude/Gemini after the provider was never configured.
     */
    public function initializeNewUserDefaults(int $userId): void
    {
        $this->logger->debug('Skipping per-user default-model seed', ['user_id' => $userId]);
    }

    /**
     * Replace per-user DEFAULTMODEL overrides with recommended defaults
     * that are actually usable on this installation.
     *
     * A recommended binding whose provider has no key is skipped; the
     * first usable model for that capability is written instead so
     * "Select suggested models" never points at a dead cloud row.
     * When providers are registered but none have credentials, overrides
     * are cleared and nothing is written — {@see isModelUsable()} treats
     * an empty usable list as "cannot tell" and would otherwise re-freeze
     * the seed catalog's cloud defaults.
     * VECTORIZE is system-wide (single Qdrant collection) and is never
     * written as a per-user override.
     *
     * @return array{removed: int, written: int, defaults: array<string, int>}
     */
    public function resetUserDefaults(int $userId): array
    {
        $userOverrides = $this->configRepository->findBy([
            'ownerId' => $userId,
            'group' => 'DEFAULTMODEL',
        ]);

        $this->configRepository->removeAll($userOverrides);
        $removed = count($userOverrides);

        if ([] !== $this->providerRegistry->getUniqueProviders()
            && [] === $this->usableProviders()
        ) {
            $this->logger->debug('Skipping suggested-model writes: no provider is available', [
                'user_id' => $userId,
                'removed' => $removed,
            ]);

            return [
                'removed' => $removed,
                'written' => 0,
                'defaults' => [],
            ];
        }

        try {
            $recommended = DefaultModelConfigSeeder::getRecommendedDefaults();
        } catch (\RuntimeException) {
            $recommended = [];
        }

        $written = 0;
        $defaults = [];

        foreach ($recommended as $capability => $modelId) {
            if ('VECTORIZE' === $capability) {
                continue;
            }

            $chosen = $this->usableRecommendedModelId($modelId)
                ?? $this->firstUsableModelForCapability($capability);
            if (null === $chosen) {
                continue;
            }

            $this->configRepository->setValue($userId, 'DEFAULTMODEL', $capability, (string) $chosen);
            $defaults[$capability] = $chosen;
            ++$written;
        }

        return [
            'removed' => $removed,
            'written' => $written,
            'defaults' => $defaults,
        ];
    }

    private function usableRecommendedModelId(int $modelId): ?int
    {
        $model = $this->modelRepository->find($modelId);
        if (!$model || !$this->isModelUsable($model)) {
            return null;
        }

        return $modelId;
    }

    public function getModelTag(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);

        if (!$model) {
            return null;
        }

        return $model->getTag();
    }

    /**
     * Native vector dimension for an embedding model.
     *
     * Pulled from `BJSON.meta.dimensions` via Model::getVectorDim().
     * Returns null when the model row is missing so callers can decide
     * whether to fall back to a sensible default or raise an error.
     */
    public function getVectorDimForModel(int $modelId): ?int
    {
        $model = $this->modelRepository->find($modelId);

        if (!$model) {
            return null;
        }

        return $model->getVectorDim();
    }

    /**
     * Get effective user ID for model selection based on message channel.
     *
     * For Email messages: always returns the sender's own user ID. The message
     * only reaches this branch once it has been mapped to a real account
     * (the null-userId guard above filters out senders we could not identify),
     * so a registered sender must get the SAME model they use in web chat —
     * whether or not they used a +keyword address (issue #1176). Unmapped
     * senders never get here, so there is no longer a hardcoded user-ID-2
     * fallback that silently overrode the sender's configured model.
     *
     * For WhatsApp messages: Returns user ID only if WhatsApp number is verified.
     * For web/other channels: Always returns the user ID (no verification required).
     *
     * This ensures unverified WhatsApp users get default models, while web users
     * and identified email senders always get their configured models.
     */
    public function getEffectiveUserIdForMessage(Message $message): ?int
    {
        $userId = $message->getUserId();
        if (!$userId) {
            return null;
        }

        $channel = $message->getMeta('channel');

        // For Email: the sender is already an identified account (guarded above),
        // so use their own configured model regardless of +keyword (issue #1176).
        if ('email' === $channel) {
            return $userId;
        }

        // For WhatsApp: only use user-specific models if verified
        if ('whatsapp' === $channel) {
            $user = $this->userRepository->find($userId);
            if (!$user) {
                return null;
            }

            if ($user->hasVerifiedPhone()) {
                return $userId;
            }

            return null;
        }

        // For web/other channels: always use user-specific models
        return $userId;
    }
}
