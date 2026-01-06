<?php

namespace App\AI\Service;

use App\AI\Exception\ProviderException;
use App\Service\CircuitBreaker;
use App\Service\File\UserUploadPathBuilder;
use App\Service\ModelConfigService;
use Psr\Log\LoggerInterface;

class AiFacade
{
    public function __construct(
        private ProviderRegistry $registry,
        private ModelConfigService $modelConfig,
        private CircuitBreaker $circuitBreaker,
        private LoggerInterface $logger,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    /**
     * Sanitize UTF-8 in messages to prevent encoding errors.
     */
    private function sanitizeMessages(array $messages): array
    {
        return array_map(function ($message) {
            if (is_array($message)) {
                foreach ($message as $key => $value) {
                    if (is_string($value)) {
                        // Remove invalid UTF-8 characters
                        $message[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                        // Remove null bytes and other problematic characters
                        $message[$key] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $message[$key]);
                    } elseif (is_array($value)) {
                        // Handle nested arrays (e.g., for vision messages with image_url)
                        $message[$key] = $this->sanitizeNestedArray($value);
                    }
                }
            }

            return $message;
        }, $messages);
    }

    /**
     * Recursively sanitize nested arrays.
     */
    private function sanitizeNestedArray(array $arr): array
    {
        foreach ($arr as $key => $value) {
            if (is_string($value)) {
                $arr[$key] = mb_convert_encoding($value, 'UTF-8', 'UTF-8');
                $arr[$key] = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $arr[$key]);
            } elseif (is_array($value)) {
                $arr[$key] = $this->sanitizeNestedArray($value);
            }
        }

        return $arr;
    }

    /**
     * Chat: Messages-Array or simple prompt.
     *
     * @param array|string $messages Messages array oder einfacher string prompt
     * @param int|null     $userId   User ID für Config-Lookup
     * @param array        $options  Additional options (provider, model, temperature, etc.)
     *
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

        // Sanitize UTF-8 to prevent encoding errors
        $messages = $this->sanitizeMessages($messages);

        $this->logger->info('AI chat request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => count($messages),
        ]);

        // Execute with Circuit Breaker protection
        try {
            $response = $this->circuitBreaker->execute(
                callback: fn () => $provider->chat($messages, $options),
                serviceName: 'ai_provider_'.$provider->getName(),
                fallback: null // NO FALLBACK - let ProviderException bubble up
            );
        } catch (ProviderException $e) {
            // Re-throw ProviderException with helpful message (no model installed, etc.)
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI chat failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('AI provider failed', 'unknown', null, 0, $e);
        }

        return [
            'content' => $response,
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => [],
        ];
    }

    /**
     * Chat with streaming support.
     *
     * @param array|string $messages       Messages array or simple string prompt
     * @param callable     $streamCallback Callback function for each chunk
     * @param int|null     $userId         User ID for config lookup
     * @param array        $options        Additional options (provider, model, temperature, etc.)
     *
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

        // Sanitize UTF-8 to prevent encoding errors
        $messages = $this->sanitizeMessages($messages);

        $this->logger->info('🔵 AiFacade: Starting chat stream', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'messages_count' => count($messages),
            'model' => $options['model'] ?? 'default',
        ]);

        // Execute streaming with Circuit Breaker protection
        try {
            $this->circuitBreaker->execute(
                callback: function () use ($provider, $messages, $streamCallback, $options) {
                    $this->logger->info('🟢 AiFacade: Calling provider chatStream');
                    $provider->chatStream($messages, $streamCallback, $options);
                    $this->logger->info('🔵 AiFacade: Provider chatStream completed');

                    return null; // void return
                },
                serviceName: 'ai_provider_'.$provider->getName(),
                fallback: null // NO FALLBACK - let ProviderException bubble up
            );
        } catch (ProviderException $e) {
            // Re-throw ProviderException with helpful message (no model installed, etc.)
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('🔴 AiFacade: Chat stream failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new ProviderException('AI provider failed for streaming', 'unknown', null, 0, $e);
        }

        return [
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? $provider->getDefaultModels()['chat'] ?? 'unknown',
            'usage' => [],
        ];
    }

    /**
     * Embedding: Text → Vector.
     *
     * @param string   $text    Text to embed
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, etc.)
     *
     * @return array Vector embedding
     */
    public function embed(string $text, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;
        $model = $options['model'] ?? null;

        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'vectorize');
        }

        $provider = $this->registry->getEmbeddingProvider($providerName);

        $this->logger->info('AI embedding request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'model' => $model ?? 'default',
            'text_length' => strlen($text),
        ]);

        return $provider->embed($text, $options);
    }

    /**
     * Batch Embedding.
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
     * Analyze Image with Vision AI.
     *
     * @param string   $imagePath Relative path to image from upload dir
     * @param string   $prompt    Analysis prompt
     * @param int|null $userId    User ID for config lookup
     * @param array    $options   Additional options (provider, model, etc.)
     *
     * @return array Response mit content, provider, model
     */
    public function analyzeImage(string $imagePath, string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerWasExplicit = array_key_exists('provider', $options);
        $requestedProvider = $options['provider'] ?? $this->modelConfig->getDefaultProvider($userId, 'pic2text');
        // Don't default to 'test' - let real providers be tried first via fallback logic
        $normalizedRequested = $requestedProvider ? strtolower($requestedProvider) : null;

        $candidates = [];

        // Prefer explicitly requested provider first (unless it is the dummy test provider)
        if ($providerWasExplicit && $requestedProvider && 'test' !== $normalizedRequested) {
            $candidates[] = [
                'name' => $requestedProvider,
                'requireCapability' => true,
            ];
        } elseif ($requestedProvider && 'test' !== $normalizedRequested) {
            $candidates[] = [
                'name' => $requestedProvider,
                'requireCapability' => true,
            ];
        }
        $this->logger->info('AI vision request - provider selection', [
            'requested_provider' => $requestedProvider,
            'user_id' => $userId,
            'image' => basename($imagePath),
            'options' => $options,
        ]);

        // Add all available real providers next (ALWAYS - even if a provider was requested)
        $fallbackRequireCapability = true;
        $fallbackProviders = $this->registry->getAvailableProviders('vision', includeTest: false, requireCapability: true);

        if (empty($fallbackProviders)) {
            $this->logger->info('AI vision fallback: no DB-enabled providers, probing available providers with API keys', [
                'requested_provider' => $requestedProvider,
                'user_id' => $userId,
            ]);

            $fallbackProviders = $this->registry->getAvailableProviders('vision', includeTest: false, requireCapability: false);
            $fallbackRequireCapability = false;
        }

        foreach ($fallbackProviders as $fallbackName) {
            if ($normalizedRequested && 0 === strcasecmp($fallbackName, $requestedProvider)) {
                continue;
            }

            $candidates[] = [
                'name' => $fallbackName,
                'requireCapability' => $fallbackRequireCapability,
            ];
        }

        // Only add TestProvider as last resort if NO real providers are available
        // TestProvider should NEVER be used in production for actual file analysis
        if (empty($candidates)) {
            $this->logger->warning('AI vision: No real providers available, falling back to TestProvider (development only)', [
                'user_id' => $userId,
            ]);
            $candidates[] = [
                'name' => 'test',
                'requireCapability' => false,
            ];
        }

        $attempted = [];
        $lastException = null;

        foreach ($candidates as $candidate) {
            $candidateName = $candidate['name'];
            $normalizedCandidate = strtolower($candidateName);

            if (in_array($normalizedCandidate, $attempted, true)) {
                continue;
            }
            $attempted[] = $normalizedCandidate;

            try {
                $provider = $this->registry->getVisionProvider($candidateName, $candidate['requireCapability']);
            } catch (ProviderException $e) {
                $this->logger->warning('AI vision provider not available', [
                    'provider' => $candidateName,
                    'require_capability' => $candidate['requireCapability'],
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
                continue;
            }

            $this->logger->info('AI vision request via '.$provider->getName(), [
                'provider' => $provider->getName(),
                'user_id' => $userId,
                'image' => basename($imagePath),
            ]);

            try {
                $response = $this->circuitBreaker->execute(
                    callback: fn () => $provider->explainImage($imagePath, $prompt, $options),
                    serviceName: 'ai_provider_vision_'.$provider->getName(),
                    fallback: null // NO FALLBACK
                );

                return [
                    'content' => $response,
                    'provider' => $provider->getName(),
                    'model' => $options['model'] ?? 'unknown',
                ];
            } catch (ProviderException $e) {
                $this->logger->warning('AI vision provider failed: '.$provider->getName().' - '.$e->getMessage(), [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $lastException = $e;
                continue;
            } catch (\Throwable $e) {
                $this->logger->error('AI vision provider error: '.$provider->getName().' - '.$e->getMessage(), [
                    'provider' => $provider->getName(),
                    'error' => $e->getMessage(),
                ]);
                $lastException = new ProviderException(
                    'Vision provider error: '.$e->getMessage(),
                    $provider->getName(),
                    null,
                    0,
                    $e
                );
                continue;
            }
        }

        $this->logger->error('AI vision failed after exhausting providers', [
            'image' => basename($imagePath),
            'attempted' => $attempted,
        ]);

        if ($lastException) {
            throw $lastException;
        }

        throw new ProviderException('Vision AI failed', 'unknown');
    }

    /**
     * Generate Image with AI (DALL-E, etc.).
     *
     * @param string   $prompt  Image generation prompt
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, size, quality, style, etc.)
     *
     * @return array Generated images with metadata
     */
    public function generateImage(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'image_generation');
        }

        $provider = $this->registry->getImageGenerationProvider($providerName);

        $this->logger->info('AI image generation request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'prompt_length' => strlen($prompt),
        ]);

        try {
            $images = $this->circuitBreaker->execute(
                callback: fn () => $provider->generateImage($prompt, $options),
                serviceName: 'ai_provider_image_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI image generation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Image generation failed', 'unknown', null, 0, $e);
        }

        return [
            'images' => $images,
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? 'unknown',
        ];
    }

    /**
     * Generate Video with AI.
     *
     * @param string   $prompt  Video generation prompt
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, duration, resolution, etc.)
     *
     * @return array Generated videos with metadata
     */
    public function generateVideo(string $prompt, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'video_generation');
        }

        $provider = $this->registry->getVideoGenerationProvider($providerName);

        $this->logger->info('AI video generation request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'prompt_length' => strlen($prompt),
        ]);

        try {
            $videos = $this->circuitBreaker->execute(
                callback: fn () => $provider->generateVideo($prompt, $options),
                serviceName: 'ai_provider_video_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI video generation failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Video generation failed', 'unknown', null, 0, $e);
        }

        return [
            'videos' => $videos,
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? 'unknown',
        ];
    }

    /**
     * Transcribe Audio (Whisper).
     *
     * @param string   $audioPath Relative path to audio file from upload dir
     * @param int|null $userId    User ID for config lookup
     * @param array    $options   Additional options (provider, model, language, etc.)
     *
     * @return array Transcription result with text, language, duration, segments
     */
    public function transcribe(string $audioPath, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'speech_to_text');
        }

        $provider = $this->registry->getSpeechToTextProvider($providerName);

        $this->logger->info('AI transcription request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'audio' => basename($audioPath),
        ]);

        try {
            $result = $this->circuitBreaker->execute(
                callback: fn () => $provider->transcribe($audioPath, $options),
                serviceName: 'ai_provider_stt_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI transcription failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('Transcription failed', 'unknown', null, 0, $e);
        }

        return array_merge($result, [
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? 'unknown',
        ]);
    }

    /**
     * Synthesize Speech (TTS).
     *
     * @param string   $text    Text to synthesize
     * @param int|null $userId  User ID for config lookup
     * @param array    $options Additional options (provider, model, voice, speed, format, etc.)
     *
     * @return array Result with relativePath (user-based path) and metadata
     */
    public function synthesize(string $text, ?int $userId = null, array $options = []): array
    {
        $providerName = $options['provider'] ?? null;

        // Wenn kein Provider explizit angegeben, nutze User-Konfiguration
        if (!$providerName && $userId > 0) {
            $providerName = $this->modelConfig->getDefaultProvider($userId, 'text_to_speech');
        }

        $provider = $this->registry->getTextToSpeechProvider($providerName);

        $this->logger->info('AI TTS request', [
            'provider' => $provider->getName(),
            'user_id' => $userId,
            'text_length' => strlen($text),
        ]);

        try {
            $filename = $this->circuitBreaker->execute(
                callback: fn () => $provider->synthesize($text, $options),
                serviceName: 'ai_provider_tts_'.$provider->getName(),
                fallback: null // NO FALLBACK
            );
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('AI TTS failed', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('TTS failed', 'unknown', null, 0, $e);
        }

        // Provider saves file to uploadDir root - move it to user-based path
        $relativePath = $this->moveToUserPath($filename, $userId, $provider->getName());

        return [
            'filename' => basename($relativePath),
            'relativePath' => $relativePath,
            'provider' => $provider->getName(),
            'model' => $options['model'] ?? 'unknown',
        ];
    }

    /**
     * Move a file from uploadDir root to user-based path structure.
     *
     * @param string   $filename     The filename in uploadDir root (e.g., tts_xxx.mp3)
     * @param int|null $userId       User ID for path generation
     * @param string   $providerName Provider name for logging
     *
     * @return string Relative path from uploadDir (e.g., 13/000/00013/2025/01/tts_xxx.mp3)
     */
    private function moveToUserPath(string $filename, ?int $userId, string $providerName): string
    {
        $sourcePath = $this->uploadDir.'/'.$filename;

        // If no userId, keep file in root (fallback)
        if (!$userId) {
            $this->logger->warning('AiFacade: No userId for TTS file, keeping in root', [
                'filename' => $filename,
                'provider' => $providerName,
            ]);

            return $filename;
        }

        // Build user-based path
        $year = date('Y');
        $month = date('m');
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
        $targetPath = $this->uploadDir.'/'.$relativePath;

        // Create directory if not exists
        $dir = dirname($targetPath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->logger->error('AiFacade: Failed to create user directory for TTS', [
                'dir' => $dir,
                'filename' => $filename,
            ]);

            // Fall back to root path
            return $filename;
        }

        // Move file
        if (!rename($sourcePath, $targetPath)) {
            $this->logger->error('AiFacade: Failed to move TTS file to user path', [
                'source' => $sourcePath,
                'target' => $targetPath,
            ]);

            // Fall back to root path
            return $filename;
        }

        $this->logger->info('AiFacade: Moved TTS file to user path', [
            'filename' => $filename,
            'relativePath' => $relativePath,
            'userId' => $userId,
        ]);

        return $relativePath;
    }
}
