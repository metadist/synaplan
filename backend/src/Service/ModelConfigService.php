<?php

namespace App\Service;

use App\AI\Service\ProviderRegistry;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use App\Seed\DefaultModelConfigSeeder;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Service für dynamische AI-Modell-Konfiguration basierend auf User-Einstellungen.
 *
 * Ermöglicht User-spezifische Default-Modelle aus BCONFIG + BMODELS Tabellen
 */
final readonly class ModelConfigService
{
    public const DEFAULT_LIGHTWEIGHT_MODEL_ID = 73;

    public function __construct(
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private UserRepository $userRepository,
        private CacheItemPoolInterface $cache,
        private ProviderRegistry $providerRegistry,
    ) {
    }

    /**
     * Holt Default-Provider für einen User und Capability.
     *
     * Reihenfolge:
     * 1. User-spezifische Config (BCONFIG: BOWNERID=userId, BGROUP='ai', BSETTING='default_chat_provider')
     * 2. Global Default Config (BOWNERID=0)
     * 3. Smart Fallback from DB
     */
    public function getDefaultProvider(?int $userId, string $capability = 'chat'): string
    {
        $cacheKey = "model_config.provider.{$userId}.{$capability}";
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        // 1. User-spezifische Config
        if ($userId) {
            $config = $this->configRepository->findByOwnerGroupAndSetting(
                $userId,
                'ai',
                "default_{$capability}_provider"
            );

            if ($config) {
                $provider = $config->getValue();
                $item->set($provider);
                $item->expiresAfter(300); // 5 Min Cache
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
     * Holt Default-Modell für einen User, Provider und Capability (OLD METHOD - DEPRECATED).
     *
     * Reihenfolge:
     * 1. User-spezifische Config (BCONFIG: 'default_chat_model')
     * 2. BMODELS Tabelle (BPROVIDER, BCAPABILITY, BISDEFAULT=1)
     * 3. ENV Variable (fallback)
     */
    public function getDefaultModelOld(?int $userId, string $provider, string $capability = 'chat'): ?string
    {
        $cacheKey = "model_config.model.{$userId}.{$provider}.{$capability}";
        $item = $this->cache->getItem($cacheKey);

        if ($item->isHit()) {
            return $item->get();
        }

        // 1. User-spezifische Config
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

        // 2. BMODELS Tabelle
        $model = $this->modelRepository->findDefaultByProviderAndCapability($provider, $capability);

        if ($model) {
            $modelName = $model->getName();
            $item->set($modelName);
            $item->expiresAfter(300);
            $this->cache->save($item);

            return $modelName;
        }

        // 3. null zurückgeben - Provider nutzt dann seinen eigenen Default
        $item->set(null);
        $item->expiresAfter(60);
        $this->cache->save($item);

        return null;
    }

    /**
     * Setzt User-spezifischen Default-Provider.
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
     * Setzt User-spezifisches Default-Modell.
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
     * Holt komplette AI-Config für einen User.
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
     * Priority: User Config > Global Config > null.
     * In test env, ConfigFixtures seeds global defaults pointing to TestProvider models.
     */
    public function getDefaultModel(string $capability, ?int $userId = null): ?int
    {
        // Try user-specific config first
        if ($userId) {
            $config = $this->configRepository->findOneBy([
                'ownerId' => $userId,
                'group' => 'DEFAULTMODEL',
                'setting' => strtoupper($capability),
            ]);

            if ($config) {
                return (int) $config->getValue();
            }
        }

        // Fall back to global config
        $config = $this->configRepository->findOneBy([
            'ownerId' => 0,
            'group' => 'DEFAULTMODEL',
            'setting' => strtoupper($capability),
        ]);

        if ($config) {
            return (int) $config->getValue();
        }

        return null;
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
     * Replace per-user DEFAULTMODEL overrides with the code-recommended
     * defaults from {@see DefaultModelConfigSeeder::getRecommendedDefaults()}.
     *
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

            $model = $this->modelRepository->find($modelId);
            if (!$model || 1 !== $model->getActive()) {
                continue;
            }

            $this->configRepository->setValue($userId, 'DEFAULTMODEL', $capability, (string) $modelId);
            $defaults[$capability] = $modelId;
            ++$written;
        }

        return [
            'removed' => $removed,
            'written' => $written,
            'defaults' => $defaults,
        ];
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
     * For Email messages:
     *   - smart@synaplan.net (no keyword) → returns user ID 2 for model selection
     *   - smart+keyword@synaplan.net (with keyword) → returns sender's user ID
     *
     * For WhatsApp messages: Returns user ID only if WhatsApp number is verified.
     * For web/other channels: Always returns the user ID (no verification required).
     *
     * This ensures unverified WhatsApp users and emails without keywords get default models,
     * while web users and emails with keywords always get their configured models.
     */
    public function getEffectiveUserIdForMessage(Message $message): ?int
    {
        $userId = $message->getUserId();
        if (!$userId) {
            return null;
        }

        $channel = $message->getMeta('channel');

        // For Email: if no keyword (smart@synaplan.net), use user ID 2 for model selection
        if ('email' === $channel) {
            $emailKeyword = $message->getMeta('email_keyword');
            // If no keyword (smart@synaplan.net without +keyword), use user ID 2
            if (empty($emailKeyword)) {
                return 2;
            }

            // If keyword exists (smart+keyword@synaplan.net), use sender's user ID
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
