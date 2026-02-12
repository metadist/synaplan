<?php

namespace App\Service;

use App\AI\Service\ProviderRegistry;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ConfigRepository;
use App\Repository\ModelRepository;
use App\Repository\UserRepository;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Service für dynamische AI-Modell-Konfiguration basierend auf User-Einstellungen.
 *
 * Ermöglicht User-spezifische Default-Modelle aus BCONFIG + BMODELS Tabellen
 */
class ModelConfigService
{
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
     * 3. Fallback: 'test'
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
     * This prevents using 'test' provider when real providers are available.
     * Looks for the first active, selectable model with matching tag,
     * but only if the provider is actually available (API key configured).
     *
     * @param string $capability The capability (chat, speech_to_text, etc.)
     *
     * @return string Provider name (lowercase) or 'test' if none found
     */
    private function findFallbackProvider(string $capability): string
    {
        // Map capability to DB tag
        $tagMap = [
            'chat' => 'chat',
            'embedding' => 'vectorize',
            'vision' => 'pic2text',
            'image_generation' => 'text2pic',
            'video_generation' => 'text2vid',
            'speech_to_text' => 'sound2text',
            'text_to_speech' => 'text2sound',
            'file_analysis' => 'analyze',
        ];

        $tag = $tagMap[$capability] ?? $capability;

        // Get actually available providers (with API keys configured)
        $availableProviders = array_map(
            'strtolower',
            $this->providerRegistry->getAvailableProviders($capability, false)
        );

        // If no real providers are available, fall back to test
        if (empty($availableProviders)) {
            return 'test';
        }

        // Find first active model with this tag where provider is available
        $models = $this->modelRepository->findByTag($tag, true);

        foreach ($models as $model) {
            $provider = strtolower($model->getService());

            // Only return providers that are actually available
            if ('test' !== $provider && in_array($provider, $availableProviders, true)) {
                return $provider;
            }
        }

        // Last resort fallback
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
        return [
            'chat' => [
                'provider' => $this->getDefaultProvider($userId, 'chat'),
                'model' => $this->getDefaultModel('CHAT', $userId),
            ],
            'vision' => [
                'provider' => $this->getDefaultProvider($userId, 'vision'),
                'model' => $this->getDefaultModel('VISION', $userId),
            ],
            'embedding' => [
                'provider' => $this->getDefaultProvider($userId, 'embedding'),
                'model' => $this->getDefaultModel('EMBEDDING', $userId),
            ],
        ];
    }

    /**
     * Get default model ID for a specific capability.
     *
     * Priority: User Config > Global Config > Fallback
     */
    public function getDefaultModel(string $capability, ?int $userId = null): ?int
    {
        // Normalize capability key
        $configKey = 'DEFAULTMODEL/'.strtoupper($capability);

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
     * @return array{provider: ?string, model: ?string}
     */
    public function getToolsModelConfig(): array
    {
        $modelId = $this->getDefaultModel('TOOLS');

        // Fallback to global CHAT default
        if (!$modelId) {
            $modelId = $this->getDefaultModel('CHAT', 0);
        }

        if (!$modelId) {
            return ['provider' => null, 'model' => null];
        }

        return [
            'provider' => $this->getProviderForModel($modelId),
            'model' => $this->getModelName($modelId),
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

    public function getModelTag(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);

        if (!$model) {
            return null;
        }

        return $model->getTag();
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
