<?php

namespace App\AI\Service;

use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
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
        
        // Map capability names: chat -> chat, embedding -> vectorize, vision -> pic2text
        $capabilityMap = [
            'chat' => 'chat',
            'embedding' => 'vectorize',
            'vision' => 'pic2text',
            'image_generation' => 'text2pic',
            'speech_to_text' => 'sound2text',
            'text_to_speech' => 'text2sound',
            'file_analysis' => 'analyze'
        ];
        
        $dbCapability = $capabilityMap[$capability] ?? $capability;
        
        return isset($dbCaps[$providerName]) && in_array($dbCapability, $dbCaps[$providerName]);
    }

    /**
     * Get provider by capability and name (with DB capability check)
     */
    private function getProvider(string $capability, ?string $name = null)
    {
        $name = $name ?? $this->defaultProvider;
        
        if (!isset($this->providers[$capability])) {
            throw new ProviderException(
                "No providers registered for capability: {$capability}",
                $name
            );
        }
        
        foreach ($this->providers[$capability] as $provider) {
            if ($provider->getName() === $name && $provider->isAvailable()) {
                // Check if capability is enabled in DB
                if (!$this->isCapabilityEnabled($name, $capability)) {
                    $this->logger->warning('Provider capability disabled in DB', [
                        'provider' => $name,
                        'capability' => $capability
                    ]);
                    throw new ProviderException(
                        "Provider '{$name}' does not support capability '{$capability}' (not in DB)",
                        $name
                    );
                }
                return $provider;
            }
        }
        
        throw new ProviderException(
            "{$capability} provider '{$name}' not found or unavailable",
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

    public function getVisionProvider(?string $name = null): VisionProviderInterface
    {
        return $this->getProvider('vision', $name);
    }

    public function getImageGenerationProvider(?string $name = null): ImageGenerationProviderInterface
    {
        return $this->getProvider('image_generation', $name);
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

    public function getAllProviders(): array
    {
        $all = [];
        foreach ($this->providers as $capability => $providers) {
            $all = array_merge($all, $providers);
        }
        return array_unique($all, SORT_REGULAR);
    }
}

