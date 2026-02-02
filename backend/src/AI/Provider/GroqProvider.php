<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use OpenAI;
use Psr\Log\LoggerInterface;

/**
 * Groq Provider - Fast LLM inference with OpenAI-compatible API
 * Supports Chat, Vision, and Speech-to-Text (Whisper).
 *
 * @see https://console.groq.com/docs/
 * @see https://console.groq.com/docs/speech-to-text
 */
class GroqProvider implements ChatProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface
{
    private $client;

    public function __construct(
        private LoggerInterface $logger,
        private ?string $apiKey = null,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
        if (!empty($apiKey)) {
            // Groq uses OpenAI-compatible client with custom base URL
            $this->client = \OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri('https://api.groq.com/openai/v1')
                ->make();
        }
    }

    public function getName(): string
    {
        return 'groq';
    }

    public function getDisplayName(): string
    {
        return 'Groq';
    }

    public function getDescription(): string
    {
        return 'Ultra-fast LLM inference with LPU technology';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'speech_to_text'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->client) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 50,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && null !== $this->client;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'GROQ_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://console.groq.com/',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'groq');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            $model = $options['model'];

            $this->logger->info('Groq chat request', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
            ];

            if (isset($options['max_tokens'])) {
                $requestOptions['max_tokens'] = $options['max_tokens'];
            }

            if (isset($options['temperature'])) {
                $requestOptions['temperature'] = $options['temperature'];
            }

            $response = $this->client->chat()->create($requestOptions);

            return $response->choices[0]->message->content ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Groq chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('Groq chat error: '.$e->getMessage(), 'groq', null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'groq');
        }

        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            $model = $options['model'];
            // Note: Qwen3 models send <think> tags directly in content, not via reasoning_format
            // reasoning_format is mainly for OpenAI o-series models

            $this->logger->info('🟢 Groq streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $requestOptions = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ];

            if (isset($options['max_tokens'])) {
                $requestOptions['max_tokens'] = $options['max_tokens'];
            }

            if (isset($options['temperature'])) {
                $requestOptions['temperature'] = $options['temperature'];
            }

            // Note: Qwen3 models automatically include <think> tags in content
            // We don't need to set reasoning_format for Groq

            $stream = $this->client->chat()->createStreamed($requestOptions);

            $chunkCount = 0;

            foreach ($stream as $response) {
                ++$chunkCount;

                // Handle reasoning content (for models with structured reasoning like OpenAI o1)
                if (isset($response->choices[0]->delta->reasoning_content)) {
                    $reasoningContent = $response->choices[0]->delta->reasoning_content;

                    $callback([
                        'type' => 'reasoning',
                        'content' => $reasoningContent,
                    ]);
                }

                // Handle regular content (may include <think> tags for models like Qwen3)
                if (isset($response->choices[0]->delta->content)) {
                    $content = $response->choices[0]->delta->content;

                    // Send as plain string (not structured) so <think> tags pass through
                    $callback($content);
                }
            }

            $this->logger->info('✅ Groq streaming COMPLETE', [
                'model' => $model,
                'chunks' => $chunkCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('Groq streaming error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('Groq streaming error: '.$e->getMessage(), 'groq', null, 0, $e);
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            // Groq supports llama-4-scout and llama-4-maverick vision models
            $model = $options['model'] ?? 'meta-llama/llama-4-scout-17b-16e-instruct';

            // Build full path
            $fullPath = $this->uploadDir.'/'.ltrim($imageUrl, '/');

            // Check if file exists
            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            // Read image and convert to base64
            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            // Default prompt if not provided
            if (empty($prompt)) {
                $prompt = 'Please describe this image in detail.';
            }

            $this->logger->info('Groq: Analyzing image', [
                'model' => $model,
                'image' => basename($imageUrl),
                'prompt_length' => strlen($prompt),
            ]);

            // Groq uses OpenAI-compatible vision API
            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => $prompt,
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType};base64,{$base64Image}",
                            ],
                        ],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? 1000,
            ]);

            return $response->choices[0]->message->content ?? '';
        } catch (\Exception $e) {
            throw new ProviderException('Groq vision error: '.$e->getMessage(), 'groq');
        }
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->explainImage($imageUrl, 'Extract all text from this image. Provide only the extracted text without any commentary.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            $model = 'meta-llama/llama-4-scout-17b-16e-instruct';

            // Build full paths
            $fullPath1 = $this->uploadDir.'/'.ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir.'/'.ltrim($imageUrl2, '/');

            // Check if files exist
            if (!file_exists($fullPath1)) {
                throw new \Exception("Image file not found: {$fullPath1}");
            }
            if (!file_exists($fullPath2)) {
                throw new \Exception("Image file not found: {$fullPath2}");
            }

            // Read images and convert to base64
            $imageData1 = file_get_contents($fullPath1);
            $base64Image1 = base64_encode($imageData1);
            $mimeType1 = mime_content_type($fullPath1);

            $imageData2 = file_get_contents($fullPath2);
            $base64Image2 = base64_encode($imageData2);
            $mimeType2 = mime_content_type($fullPath2);

            $this->logger->info('Groq: Comparing images', [
                'model' => $model,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ]);

            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Compare these two images and describe the differences and similarities.',
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType1};base64,{$base64Image1}",
                            ],
                        ],
                        [
                            'type' => 'image_url',
                            'image_url' => [
                                'url' => "data:{$mimeType2};base64,{$base64Image2}",
                            ],
                        ],
                    ],
                ]],
                'max_tokens' => 1000,
            ]);

            return [
                'comparison' => $response->choices[0]->message->content ?? '',
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException('Groq image comparison error: '.$e->getMessage(), 'groq');
        }
    }

    // ==================== SPEECH TO TEXT (Whisper) ====================

    /**
     * Transcribe audio file using Groq's Whisper API.
     *
     * Supported models:
     * - whisper-large-v3: Best accuracy, supports translation, $0.111/hour
     * - whisper-large-v3-turbo: Faster, cheaper ($0.04/hour), no translation
     *
     * @see https://console.groq.com/docs/speech-to-text
     *
     * @param string $audioPath Relative path to audio file from upload dir
     * @param array  $options   Options: model, language (ISO-639-1), prompt, temperature
     *
     * @return array Transcription result with text, language, duration, segments
     */
    public function transcribe(string $audioPath, array $options = []): array
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'whisper-large-v3-turbo';

            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            // Check file size (Groq limits: 25MB free tier, 100MB dev tier)
            $fileSize = filesize($fullPath);
            $maxSize = 25 * 1024 * 1024; // 25MB conservative limit
            if ($fileSize > $maxSize) {
                throw new \Exception('Audio file too large: '.round($fileSize / 1024 / 1024, 2).'MB (max 25MB)');
            }

            $this->logger->info('Groq: Transcribing audio', [
                'model' => $model,
                'file' => basename($audioPath),
                'path' => $fullPath,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
            ]);

            // Open file and ensure it's a valid resource
            $fileHandle = fopen($fullPath, 'r');
            if (!$fileHandle) {
                throw new \Exception("Failed to open audio file: {$fullPath}");
            }

            // Build request parameters
            $requestParams = [
                'model' => $model,
                'file' => $fileHandle,
                'response_format' => 'verbose_json',
            ];

            // Optional: language hint (ISO-639-1 code) improves accuracy and latency
            if (!empty($options['language'])) {
                $requestParams['language'] = $options['language'];
            }

            // Optional: prompt for context/spelling guidance (max 224 tokens)
            if (!empty($options['prompt'])) {
                $requestParams['prompt'] = substr($options['prompt'], 0, 1000); // Limit prompt length
            }

            // Optional: temperature (0-1, default 0)
            if (isset($options['temperature'])) {
                $requestParams['temperature'] = (float) $options['temperature'];
            }

            $response = $this->client->audio()->transcribe($requestParams);

            $this->logger->info('Groq: Transcription complete', [
                'model' => $model,
                'duration_seconds' => $response['duration'] ?? 0,
                'text_length' => strlen($response['text'] ?? ''),
            ]);

            return [
                'text' => $response['text'] ?? '',
                'language' => $response['language'] ?? 'unknown',
                'duration' => $response['duration'] ?? 0,
                'segments' => $response['segments'] ?? [],
            ];
        } catch (\Exception $e) {
            $this->logger->error('Groq transcription error', [
                'error' => $e->getMessage(),
                'file' => basename($audioPath),
            ]);

            throw new ProviderException('Groq transcription error: '.$e->getMessage(), 'groq', null, 0, $e);
        }
    }

    /**
     * Translate audio to English using Groq's Whisper translation endpoint.
     *
     * Note: Only whisper-large-v3 supports translation. whisper-large-v3-turbo does NOT.
     * The translation endpoint always translates TO English.
     *
     * @see https://console.groq.com/docs/speech-to-text
     *
     * @param string $audioPath  Relative path to audio file from upload dir
     * @param string $targetLang Target language (only 'en' supported by Whisper)
     *
     * @return string Translated text in English
     */
    public function translateAudio(string $audioPath, string $targetLang): string
    {
        if (!$this->client) {
            throw ProviderException::missingApiKey('groq', 'GROQ_API_KEY');
        }

        // Whisper's translate endpoint only supports English output
        if ('en' !== strtolower($targetLang)) {
            $this->logger->warning('Groq Whisper translation only supports English output', [
                'requested_lang' => $targetLang,
                'using' => 'en',
            ]);
        }

        try {
            // Only whisper-large-v3 supports translation (NOT turbo)
            $model = 'whisper-large-v3';

            // Handle both absolute and relative paths
            $fullPath = str_starts_with($audioPath, '/')
                ? $audioPath
                : $this->uploadDir.'/'.ltrim($audioPath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Audio file not found: {$fullPath}");
            }

            $this->logger->info('Groq: Translating audio to English', [
                'model' => $model,
                'file' => basename($audioPath),
            ]);

            $response = $this->client->audio()->translate([
                'model' => $model,
                'file' => fopen($fullPath, 'r'),
                'response_format' => 'text',
            ]);

            return $response['text'] ?? '';
        } catch (\Exception $e) {
            $this->logger->error('Groq audio translation error', [
                'error' => $e->getMessage(),
                'file' => basename($audioPath),
            ]);

            throw new ProviderException('Groq audio translation error: '.$e->getMessage(), 'groq', null, 0, $e);
        }
    }
}
