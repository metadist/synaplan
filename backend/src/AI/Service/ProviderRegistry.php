<?php

declare(strict_types=1);

namespace App\AI\Service;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\Repository\ModelRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Contracts\Service\ServiceProviderInterface;

/**
 * Provider Registry with DB-driven Capabilities.
 *
 * Providers register with all their interfaces (one `app.ai.<capability>` tag
 * per interface, each carrying `key: <getName()>`), but DB (BMODELS.BTAG)
 * controls which capabilities are actually available.
 *
 * Providers are resolved lazily: a by-name lookup instantiates exactly the
 * provider asked for, so a chat request never constructs the Ollama client,
 * the Triton gRPC stub or the Piper temp directories it does not use. The
 * listing methods (`getAvailableProviders`, `getAllProviders`,
 * `getUniqueProviders`, `getProvidersMetadata`) still instantiate every
 * provider they report on — they are status/health surfaces, not the hot path.
 * `ProviderRegistryKeyTest` proves every tag key equals the provider's name.
 */
class ProviderRegistry
{
    /** Registry capability keys in registration order (drives listing order). */
    public const CAPABILITIES = [
        'chat',
        'embedding',
        'vision',
        'image_generation',
        'video_generation',
        'speech_to_text',
        'text_to_speech',
        'file_analysis',
    ];

    /** @var array<string, ServiceProviderInterface> capability → locator keyed by provider name */
    private array $locators;
    private ?array $dbCapabilities = null;

    public function __construct(
        #[AutowireLocator('app.ai.chat', indexAttribute: 'key')]
        ServiceProviderInterface $chatProviders,
        #[AutowireLocator('app.ai.embedding', indexAttribute: 'key')]
        ServiceProviderInterface $embeddingProviders,
        #[AutowireLocator('app.ai.vision', indexAttribute: 'key')]
        ServiceProviderInterface $visionProviders,
        #[AutowireLocator('app.ai.image_generation', indexAttribute: 'key')]
        ServiceProviderInterface $imageGenerationProviders,
        #[AutowireLocator('app.ai.video_generation', indexAttribute: 'key')]
        ServiceProviderInterface $videoGenerationProviders,
        #[AutowireLocator('app.ai.speech_to_text', indexAttribute: 'key')]
        ServiceProviderInterface $speechToTextProviders,
        #[AutowireLocator('app.ai.text_to_speech', indexAttribute: 'key')]
        ServiceProviderInterface $textToSpeechProviders,
        #[AutowireLocator('app.ai.file_analysis', indexAttribute: 'key')]
        ServiceProviderInterface $fileAnalysisProviders,
        private ModelRepository $modelRepository,
        private LoggerInterface $logger,
        private string $defaultProvider = 'test',
        #[Autowire('%kernel.environment%')]
        private string $appEnv = 'prod',
    ) {
        $this->locators = [
            'chat' => $chatProviders,
            'embedding' => $embeddingProviders,
            'vision' => $visionProviders,
            'image_generation' => $imageGenerationProviders,
            'video_generation' => $videoGenerationProviders,
            'speech_to_text' => $speechToTextProviders,
            'text_to_speech' => $textToSpeechProviders,
            'file_analysis' => $fileAnalysisProviders,
        ];
    }

    /**
     * Load capabilities from DB (cached after first call).
     */
    private function loadDbCapabilities(): array
    {
        if (null === $this->dbCapabilities) {
            $this->dbCapabilities = $this->modelRepository->getProviderCapabilities();
            $this->logger->info('Loaded provider capabilities from DB', [
                'capabilities' => $this->dbCapabilities,
            ]);
        }

        return $this->dbCapabilities;
    }

    /**
     * Check if provider supports capability according to DB.
     */
    private function isCapabilityEnabled(string $providerName, string $capability): bool
    {
        $dbCaps = $this->loadDbCapabilities();

        // Normalize provider name (case-insensitive)
        $providerName = strtolower($providerName);

        if ($this->canUseInternalTestProvider($providerName)) {
            return true;
        }

        // Normalize DB keys to lowercase
        $dbCaps = array_change_key_case($dbCaps, CASE_LOWER);

        $this->logger->debug('Capability check', [
            'provider' => $providerName,
            'capability' => $capability,
            'in_db' => isset($dbCaps[$providerName]),
        ]);

        // Map capability names: chat -> chat, embedding -> vectorize, vision -> pic2text
        $capabilityMap = [
            'chat' => 'chat',
            'embedding' => 'vectorize',
            'vision' => 'pic2text',
            'image_generation' => 'text2pic',
            'video_generation' => 'text2vid',
            'speech_to_text' => 'sound2text',
            'text_to_speech' => 'text2sound',
            'file_analysis' => 'analyze',
        ];

        $dbCapability = $capabilityMap[$capability] ?? $capability;

        return isset($dbCaps[$providerName]) && in_array($dbCapability, $dbCaps[$providerName]);
    }

    private function canUseInternalTestProvider(string $providerName): bool
    {
        $appEnv = strtolower($this->appEnv);

        return 'test' === $providerName && 'prod' !== $appEnv;
    }

    /**
     * Provider names registered for a capability, without instantiating any of them.
     *
     * @return list<string>
     */
    public function getRegisteredProviderNames(string $capability): array
    {
        $locator = $this->locators[$capability] ?? null;

        return null === $locator ? [] : array_keys($locator->getProvidedServices());
    }

    /**
     * Every provider registered for a capability, keyed by its tag key (= name).
     * Instantiates all of them — a listing surface, not the hot path.
     *
     * @return array<string, ProviderMetadataInterface>
     */
    public function getProvidersForCapability(string $capability): array
    {
        $locator = $this->locators[$capability] ?? null;
        if (null === $locator) {
            return [];
        }

        $providers = [];
        foreach (array_keys($locator->getProvidedServices()) as $name) {
            $provider = $locator->get($name);
            if ($provider instanceof ProviderMetadataInterface) {
                $providers[$name] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Get provider by capability and name (with DB capability check).
     *
     * CASE-INSENSITIVE: Supports both 'Ollama' and 'ollama'. Only the requested
     * provider is instantiated.
     */
    private function getProvider(string $capability, ?string $name = null, bool $requireCapability = true)
    {
        $name = $name ?? $this->defaultProvider;

        // Normalize to lowercase for case-insensitive matching (tag keys are lowercase names)
        $normalizedName = strtolower($name);

        $locator = $this->locators[$capability] ?? null;
        if (null === $locator || [] === $locator->getProvidedServices()) {
            throw new ProviderException("No providers registered for capability: {$capability}", $name);
        }

        if ($locator->has($normalizedName)) {
            $provider = $locator->get($normalizedName);
            $isAvailable = $provider->isAvailable();

            $this->logger->debug('Provider lookup', [
                'capability' => $capability,
                'provider' => $normalizedName,
                'requested' => $normalizedName,
                'available' => $isAvailable,
                'match' => true,
            ]);

            if ($isAvailable) {
                // Check if capability is enabled in DB (using normalized name)
                if ($requireCapability && !$this->isCapabilityEnabled($normalizedName, $capability)) {
                    $this->logger->warning('Provider capability disabled in DB', [
                        'provider' => $name,
                        'normalized' => $normalizedName,
                        'capability' => $capability,
                    ]);
                    throw new ProviderException("Provider '{$name}' does not support capability '{$capability}' (not in DB)", $name);
                }

                $this->logger->debug('Provider found and available', [
                    'provider' => $provider->getName(),
                    'capability' => $capability,
                    'requested_name' => $name,
                ]);

                return $provider;
            }
        }

        // Enhanced error message with available providers
        throw new ProviderException("{$capability} provider '{$name}' not found or unavailable. Available: ".implode(', ', $this->getRegisteredProviderNames($capability)), $name);
    }

    public function getChatProvider(?string $name = null): ChatProviderInterface
    {
        return $this->getProvider('chat', $name);
    }

    public function getEmbeddingProvider(?string $name = null): EmbeddingProviderInterface
    {
        return $this->getProvider('embedding', $name);
    }

    public function getVisionProvider(?string $name = null, bool $requireCapability = true): VisionProviderInterface
    {
        return $this->getProvider('vision', $name, $requireCapability);
    }

    public function getImageGenerationProvider(?string $name = null): ImageGenerationProviderInterface
    {
        return $this->getProvider('image_generation', $name);
    }

    public function getVideoGenerationProvider(?string $name = null): VideoGenerationProviderInterface
    {
        $dbCaps = $this->loadDbCapabilities();

        $this->logger->debug('Video provider lookup', [
            'requested' => $name ?? 'DEFAULT',
            'registered' => $this->getRegisteredProviderNames('video_generation'),
            'db_google' => $dbCaps['google'] ?? 'NOT_FOUND',
        ]);

        return $this->getProvider('video_generation', $name);
    }

    public function getSpeechToTextProvider(?string $name = null): SpeechToTextProviderInterface
    {
        return $this->getProvider('speech_to_text', $name);
    }

    public function getTextToSpeechProvider(?string $name = null): TextToSpeechProviderInterface
    {
        return $this->getProvider('text_to_speech', $name);
    }

    public function getFileAnalysisProvider(?string $name = null): FileAnalysisProviderInterface
    {
        return $this->getProvider('file_analysis', $name);
    }

    /**
     * Return available providers for a capability.
     *
     * @param string $capability  Registry capability key (chat, vision, embedding, image_generation, video_generation, speech_to_text, text_to_speech, file_analysis)
     * @param bool   $includeTest Whether to include the internal TestProvider in the results
     *
     * @return string[] List of provider names (preserves provider casing)
     */
    public function getAvailableProviders(string $capability, bool $includeTest = true, bool $requireCapability = true): array
    {
        $available = [];

        foreach ($this->getProvidersForCapability($capability) as $provider) {
            $normalized = strtolower($provider->getName());

            if (!$includeTest && 'test' === $normalized) {
                continue;
            }

            if (!$provider->isAvailable()) {
                continue;
            }

            if ($requireCapability && !$this->isCapabilityEnabled($normalized, $capability)) {
                continue;
            }

            $available[] = $provider->getName();
        }

        return $available;
    }

    public function getAllProviders(): array
    {
        $all = [];
        foreach (self::CAPABILITIES as $capability) {
            $all = array_merge($all, $this->getProvidersForCapability($capability));
        }

        return array_unique($all, SORT_REGULAR);
    }

    /**
     * Get all unique providers (deduplicated by name)
     * Returns array keyed by provider name.
     */
    public function getUniqueProviders(): array
    {
        $unique = [];
        foreach (self::CAPABILITIES as $capability) {
            foreach ($this->getProvidersForCapability($capability) as $provider) {
                $name = $provider->getName();
                if (!isset($unique[$name])) {
                    $unique[$name] = $provider;
                }
            }
        }

        return $unique;
    }

    /**
     * Get metadata for all providers (for status/features UI).
     *
     * @return array provider metadata with status, capabilities, env vars, etc
     */
    public function getProvidersMetadata(): array
    {
        $metadata = [];
        $uniqueProviders = $this->getUniqueProviders();

        foreach ($uniqueProviders as $name => $provider) {
            $status = $provider->getStatus();
            $envVars = $provider->getRequiredEnvVars();

            $metadata[$name] = [
                'id' => $name,
                'name' => $provider->getDisplayName(),
                'description' => $provider->getDescription(),
                'capabilities' => $provider->getCapabilities(),
                'enabled' => $provider->isAvailable(),
                'status' => $status['healthy'] ? 'healthy' : 'unhealthy',
                'status_message' => $status['error'] ?? $provider->getDescription(),
                'setup_required' => !$provider->isAvailable(),
                'env_vars' => $envVars,
            ];

            // Add additional status info if available
            if (isset($status['latency_ms'])) {
                $metadata[$name]['latency_ms'] = $status['latency_ms'];
            }
            if (isset($status['models'])) {
                $metadata[$name]['models_available'] = $status['models'];
            }
        }

        return $metadata;
    }
}
