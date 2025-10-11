<?php

namespace App\AI\Service;

use App\AI\Exception\ProviderException;
use App\Service\CircuitBreaker;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

class AiFacade
{
    public function __construct(
        private ProviderRegistry $registry,
        private ModelConfigService $modelConfig,
        private CircuitBreaker $circuitBreaker,
        private LoggerInterface $logger
    ) {}

    /**
     * Chat: Messages-Array or simple prompt
     * 
     * @param array|string $messages Messages array oder einfacher string prompt
     * @param int|null $userId User ID für Config-Lookup
     * @param array $options Additional options (provider, model, temperature, etc.)
     * @return array Response mit content, provider, model, usage
     */
    public function chat(array|string $messages, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;
        
        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'chat');
        }
        
        $provider = $this->registry->getChatProvider($providerName);
        
        // String zu Messages konvertieren
        if (is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }
        
        $this->logger->info('AI chat request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => count($messages),
        ]);
        
        // Execute with Circuit Breaker protection
        try {
            $response = $this->circuitBreaker->execute(
                callback: fn() => $provider->chat($messages, $options),
                serviceName: 'ai_provider_' . $provider->getName(),
                fallback: function() use ($messages, $options) {
                    // Fallback zu anderem Provider
                    $this->logger->warning('Using fallback provider');
                    $fallbackProvider = $this->registry->getChatProvider('test');
                    return $fallbackProvider->chat($messages, $options);
                }
            );
        } catch (\Exception $e) {
            $this->logger->error('AI chat failed with all providers', [
                'error' => $e->getMessage()
            ]);
            throw new ProviderException('All AI providers failed', $e);
        }
        
        return [
            'content' => $response,
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => [],
        ];
    }

    /**
     * Chat with streaming support
     * 
     * @param array|string $messages Messages array or simple string prompt
     * @param callable $streamCallback Callback function for each chunk
     * @param int|null $userId User ID for config lookup
     * @param array $options Additional options (provider, model, temperature, etc.)
     * @return array Metadata (provider, model, usage)
     */
    public function chatStream(array|string $messages, callable $streamCallback, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;
        
        // If no provider specified, use user configuration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'chat');
        }
        
        $provider = $this->registry->getChatProvider($providerName);
        
        // Convert string to messages format
        if (is_string($messages)) {
            $messages = [['role' => 'user', 'content' => $messages]];
        }
        
        $this->logger->info('🔵 AiFacade: Starting chat stream', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => count($messages),
            'model' => $options['model'] ?? 'default'
        ]);
        
        // Execute streaming with Circuit Breaker protection
        try {
            $this->circuitBreaker->execute(
                callback: function() use ($provider, $messages, $streamCallback, $options) {
                    $this->logger->info('🟢 AiFacade: Calling provider chatStream');
                    $provider->chatStream($messages, $streamCallback, $options);
                    $this->logger->info('🔵 AiFacade: Provider chatStream completed');
                    return null; // void return
                },
                serviceName: 'ai_provider_' . $provider->getName(),
                fallback: function() use ($messages, $streamCallback, $options) {
                    $this->logger->warning('⚠️  AiFacade: Using fallback provider for streaming');
                    $fallbackProvider = $this->registry->getChatProvider('test');
                    $fallbackProvider->chatStream($messages, $streamCallback, $options);
                    return null;
                }
            );
        } catch (\Exception $e) {
            $this->logger->error('🔴 AiFacade: Chat stream failed with all providers', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            throw new ProviderException('All AI providers failed for streaming', $e);
        }
        
        return [
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => [],
        ];
    }

    /**
     * Embedding: Text → Vector
     * 
     * @param string $text Text to embed
     * @param int|null $userId User ID for config lookup
     * @param string|null $providerName Override provider
     * @return array Vector embedding
     */
    public function embed(string $text, ?int $userId = null, ?string $providerName = null): array
    {
        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'vectorize');
        }
        
        $provider = $this->registry->getEmbeddingProvider($providerName);
        
        $this->logger->info('AI embedding request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'text_length' => strlen($text),
        ]);
        
        return $provider->embed($text);
    }

    /**
     * Batch Embedding
     */
    public function embedBatch(array $texts, ?int $userId = null, ?string $providerName = null): array
    {
        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'vectorize');
        }
        
        $provider = $this->registry->getEmbeddingProvider($providerName);
        
        $this->logger->info('AI batch embedding request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'count' => count($texts),
        ]);
        
        return $provider->embedBatch($texts);
    }

    /**
     * Analyze Image with Vision AI
     * 
     * @param string $imagePath Relative path to image from upload dir
     * @param string $prompt Analysis prompt
     * @param int|null $userId User ID for config lookup
     * @return array Response mit content, provider, model
     */
    public function analyzeImage(string $imagePath, string $prompt, ?int $userId = null): array
    {
        $providerName = null;
        
        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if ($userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'pic2text');
        }
        
        $provider = $this->registry->getVisionProvider($providerName);
        
        $this->logger->info('AI vision request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'image' => basename($imagePath),
        ]);
        
        try {
            $response = $this->circuitBreaker->execute(
                callback: fn() => $provider->analyzeImage($imagePath, $prompt),
                serviceName: 'ai_provider_vision_' . $provider->getName(),
                fallback: function() use ($imagePath, $prompt) {
                    $this->logger->warning('Using fallback vision provider');
                    $fallbackProvider = $this->registry->getVisionProvider('test');
                    return $fallbackProvider->analyzeImage($imagePath, $prompt);
                }
            );
        } catch (\Exception $e) {
            $this->logger->error('AI vision failed', [
                'error' => $e->getMessage()
            ]);
            throw new ProviderException('Vision AI failed', $e);
        }
        
        return [
            'content' => $response,
            'provider' => $provider->getName(),
            'model' => $provider->getDefaultModels()['vision'] ?? 'unknown',
        ];
    }
}

