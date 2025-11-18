<?php

namespace App\AI\Service;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\FileAnalysisProviderInterface;
use App\AI\Exception\ProviderException;
use App\Repository\ModelRepository;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;
use Psr\Log\LoggerInterface;

/**
 * Provider Registry with DB-driven Capabilities
 * 
 * Providers register with all their interfaces,
 * but DB (BMODELS.BTAG) controls which capabilities are actually available
 */
class ProviderRegistry
{
    private array $providers = [];
    private ?array $dbCapabilities = null;

    public function __construct(
        #[TaggedIterator('app.ai.chat')]
        iterable $chatProviders = [],
        #[TaggedIterator('app.ai.embedding')]
        iterable $embeddingProviders = [],
        #[TaggedIterator('app.ai.vision')]
        iterable $visionProviders = [],
        #[TaggedIterator('app.ai.image_generation')]
        iterable $imageGenerationProviders = [],
        #[TaggedIterator('app.ai.video_generation')]
        iterable $videoGenerationProviders = [],
        #[TaggedIterator('app.ai.speech_to_text')]
        iterable $speechToTextProviders = [],
        #[TaggedIterator('app.ai.text_to_speech')]
        iterable $textToSpeechProviders = [],
        #[TaggedIterator('app.ai.file_analysis')]
        iterable $fileAnalysisProviders = [],
        private ModelRepository $modelRepository,
        private LoggerInterface $logger,
        private string $defaultProvider = 'test'
    ) {
        // Index providers by their getName() method dynamically
        foreach ($chatProviders as $provider) {
            $this->providers['chat'][$provider->getName()] = $provider;
        }
        foreach ($embeddingProviders as $provider) {
            $this->providers['embedding'][$provider->getName()] = $provider;
        }
        foreach ($visionProviders as $provider) {
            $this->providers['vision'][$provider->getName()] = $provider;
        }
        foreach ($imageGenerationProviders as $provider) {
            $this->providers['image_generation'][$provider->getName()] = $provider;
        }
        foreach ($videoGenerationProviders as $provider) {
            $this->providers['video_generation'][$provider->getName()] = $provider;
        }
        foreach ($speechToTextProviders as $provider) {
            $this->providers['speech_to_text'][$provider->getName()] = $provider;
        }
        foreach ($textToSpeechProviders as $provider) {
            $this->providers['text_to_speech'][$provider->getName()] = $provider;
        }
        foreach ($fileAnalysisProviders as $provider) {
            $this->providers['file_analysis'][$provider->getName()] = $provider;
        }
    }

    /**
     * Load capabilities from DB (cached after first call)
     */
    private function loadDbCapabilities(): array
    {
        if ($this->dbCapabilities === null) {
            $this->dbCapabilities = $this->modelRepository->getProviderCapabilities();
            $this->logger->info('Loaded provider capabilities from DB', [
                'capabilities' => $this->dbCapabilities
            ]);
        }
        return $this->dbCapabilities;
    }

    /**
     * Check if provider supports capability according to DB
     */
    private function isCapabilityEnabled(string $providerName, string $capability): bool
    {
        // EXCEPTION: TestProvider is always enabled (for unit tests & development)
        if (strtolower($providerName) === 'test') {
            return true;
        }
        
        $dbCaps = $this->loadDbCapabilities();
        
        // Normalize provider name (case-insensitive)
        $providerName = strtolower($providerName);
        
        // Normalize DB keys to lowercase
        $dbCaps = array_change_key_case($dbCaps, CASE_LOWER);
        
        $this->logger->error('🔍 CAPABILITY CHECK: provider=' . $providerName . ' | capability=' . $capability . ' | dbCaps_keys=' . json_encode(array_keys($dbCaps)) . ' | provider_in_db=' . (isset($dbCaps[$providerName]) ? 'YES' : 'NO'));
        
        // Map capability names: chat -> chat, embedding -> vectorize, vision -> pic2text
        $capabilityMap = [
            'chat' => 'chat',
            'embedding' => 'vectorize',
            'vision' => 'pic2text',
            'image_generation' => 'text2pic',
            'video_generation' => 'text2vid',
            'speech_to_text' => 'sound2text',
            'text_to_speech' => 'text2sound',
            'file_analysis' => 'analyze'
        ];
        
        $dbCapability = $capabilityMap[$capability] ?? $capability;
        
        return isset($dbCaps[$providerName]) && in_array($dbCapability, $dbCaps[$providerName]);
    }

    /**
     * Get provider by capability and name (with DB capability check)
     * 
     * CASE-INSENSITIVE: Supports both 'Ollama' and 'ollama'
     */
    private function getProvider(string $capability, ?string $name = null, bool $requireCapability = true)
    {
        $name = $name ?? $this->defaultProvider;
        
        // Normalize to lowercase for case-insensitive matching
        $normalizedName = strtolower($name);
        
        if (!isset($this->providers[$capability])) {
            throw new ProviderException(
                "No providers registered for capability: {$capability}",
                $name
            );
        }
        
        foreach ($this->providers[$capability] as $provider) {
            $providerLower = strtolower($provider->getName());
            $isAvailable = $provider->isAvailable();
            
            $this->logger->error('🔍 PROVIDER LOOP: capability=' . $capability . ' | providerLower=' . $providerLower . ' | normalizedName=' . $normalizedName . ' | isAvailable=' . ($isAvailable ? 'YES' : 'NO') . ' | match=' . ($providerLower === $normalizedName ? 'YES' : 'NO'));
            
            // Case-insensitive comparison
            if ($providerLower === $normalizedName && $isAvailable) {
                // Check if capability is enabled in DB (using normalized name)
                if ($requireCapability && !$this->isCapabilityEnabled($normalizedName, $capability)) {
                    $this->logger->warning('Provider capability disabled in DB', [
                        'provider' => $name,
                        'normalized' => $normalizedName,
                        'capability' => $capability
                    ]);
                    throw new ProviderException(
                        "Provider '{$name}' does not support capability '{$capability}' (not in DB)",
                        $name
                    );
                }
                
                $this->logger->debug('Provider found and available', [
                    'provider' => $provider->getName(),
                    'capability' => $capability,
                    'requested_name' => $name
                ]);
                
                return $provider;
            }
        }
        
        // Enhanced error message with available providers
        $availableProviders = array_map(
            fn($p) => $p->getName(),
            $this->providers[$capability] ?? []
        );
        
        throw new ProviderException(
            "{$capability} provider '{$name}' not found or unavailable. Available: " . implode(', ', $availableProviders),
            $name
        );
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
        
        $registered = array_keys($this->providers['video_generation'] ?? []);
        $allCaps = array_keys($this->providers);
        $dbGoogle = $dbCaps['google'] ?? 'NOT_FOUND';
        
        $this->logger->error('🎬 VIDEO DEBUG: requested=' . ($name ?? 'DEFAULT') 
            . ' | registered=' . json_encode($registered)
            . ' | all_caps=' . json_encode($allCaps)
            . ' | db_google=' . json_encode($dbGoogle));
        
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
     * @param string $capability Registry capability key (chat, vision, embedding, image_generation, video_generation, speech_to_text, text_to_speech, file_analysis)
     * @param bool $includeTest  Whether to include the internal TestProvider in the results
     *
     * @return string[] List of provider names (preserves provider casing)
     */
    public function getAvailableProviders(string $capability, bool $includeTest = true, bool $requireCapability = true): array
    {
        $available = [];

        if (!isset($this->providers[$capability]) || empty($this->providers[$capability])) {
            return $available;
        }

        foreach ($this->providers[$capability] as $provider) {
            $normalized = strtolower($provider->getName());

            if (!$includeTest && $normalized === 'test') {
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
        foreach ($this->providers as $capability => $providers) {
            $all = array_merge($all, $providers);
        }
        return array_unique($all, SORT_REGULAR);
    }
}

