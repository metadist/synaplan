<?php

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google AI Provider.
 *
 * Supports:
 * - Gemini 2.0 Flash, Gemini 2.5 Pro (Chat, Vision)
 * - Imagen 3.0, Gemini 2.5 Flash Image (Image Generation)
 * - Veo 2.0 (Video Generation)
 * - Text-to-Speech with Gemini
 */
class GoogleProvider implements ChatProviderInterface, ImageGenerationProviderInterface, VideoGenerationProviderInterface, VisionProviderInterface, TextToSpeechProviderInterface
{
    private const API_BASE = 'https://generativelanguage.googleapis.com/v1beta';
    private const VERTEX_BASE = 'https://{region}-aiplatform.googleapis.com/v1';

    public function __construct(
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private ?string $apiKey = null,
        private ?string $projectId = null,
        private string $region = 'us-central1',
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
        // Ensure projectId is null if empty string
        if (empty($this->projectId)) {
            $this->projectId = null;
        }
    }

    public function getName(): string
    {
        return 'google';
    }

    public function getDisplayName(): string
    {
        return 'Google AI';
    }

    public function getDescription(): string
    {
        return 'Gemini models with multimodal capabilities including video';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'video_generation', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (!$this->apiKey) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 100,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey);
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'GOOGLE_GEMINI_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://aistudio.google.com/app/apikey',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'google');
        }

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'];
            $contents = $this->convertMessagesToGeminiFormat($messages);

            $url = self::API_BASE."/models/{$model}:generateContent";

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'topP' => $options['top_p'] ?? 0.95,
                    'topK' => $options['top_k'] ?? 40,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                ],
            ];

            $this->logger->info('Google: Generating chat completion', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException('Google chat error: '.$e->getMessage(), 'google');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'google');
        }

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'];
            $contents = $this->convertMessagesToGeminiFormat($messages);

            $url = self::API_BASE."/models/{$model}:streamGenerateContent?alt=sse";

            $payload = [
                'contents' => $contents,
                'generationConfig' => [
                    'temperature' => $options['temperature'] ?? 0.7,
                    'topP' => $options['top_p'] ?? 0.95,
                    'topK' => $options['top_k'] ?? 40,
                    'maxOutputTokens' => $options['max_tokens'] ?? 2048,
                ],
            ];

            $this->logger->info('Google: Streaming chat completion', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isLast()) {
                    break;
                }

                $content = $chunk->getContent();

                // Parse SSE format: data: {...}
                if (str_starts_with($content, 'data: ')) {
                    $jsonData = substr($content, 6);
                    $data = json_decode($jsonData, true);

                    if (isset($data['candidates'][0]['content']['parts'][0]['text'])) {
                        $callback($data['candidates'][0]['content']['parts'][0]['text']);
                    }
                }
            }
        } catch (\Exception $e) {
            throw new ProviderException('Google streaming error: '.$e->getMessage(), 'google');
        }
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'imagen-3.0-generate-002';

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        // Determine API type: explicit config > auto-detect from model name
        $modelConfig = $options['modelConfig'] ?? [];
        $apiType = $modelConfig['api'] ?? null;

        if (!$apiType) {
            $apiType = $this->isGeminiNativeImageModel($model) ? 'gemini' : 'vertex';
        }

        $this->logger->info('Google: Generating image', [
            'model' => $model,
            'api_type' => $apiType,
            'prompt_length' => strlen($prompt),
        ]);

        return match ($apiType) {
            'gemini' => $this->generateImageWithGemini($model, $prompt, $options),
            default => $this->generateImageWithImagen($model, $prompt, $options),
        };
    }

    /**
     * Check if model uses Gemini native image generation API.
     * Pattern: gemini-*-image* (e.g., gemini-2.5-flash-image, gemini-3-pro-image-preview).
     */
    private function isGeminiNativeImageModel(string $model): bool
    {
        return (bool) preg_match('/^gemini-.*-image/', $model);
    }

    /**
     * Generate image using Gemini native API (Nano Banana).
     *
     * @see https://ai.google.dev/gemini-api/docs/image-generation
     */
    private function generateImageWithGemini(string $model, string $prompt, array $options): array
    {
        try {
            $url = self::API_BASE."/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $prompt],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                ],
            ];

            // Add aspect ratio if specified
            if (!empty($options['aspect_ratio'])) {
                $payload['generationConfig']['imageConfig'] = [
                    'aspectRatio' => $options['aspect_ratio'],
                ];
            }

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = $response->toArray();

            $parts = $data['candidates'][0]['content']['parts'] ?? [];

            // Log response structure for debugging
            $partTypes = [];
            $textContent = null;
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $partTypes[] = 'text';
                    $textContent = substr($part['text'], 0, 200);
                }
                if (isset($part['inline_data']) || isset($part['inlineData'])) {
                    $partTypes[] = 'image';
                }
            }

            $this->logger->info('Google Gemini image response', [
                'has_candidates' => isset($data['candidates']),
                'parts_count' => count($parts),
                'part_types' => $partTypes,
                'text_preview' => $textContent,
            ]);

            $images = [];
            foreach ($parts as $part) {
                // Handle both snake_case and camelCase response formats
                $inlineData = $part['inline_data'] ?? $part['inlineData'] ?? null;
                if ($inlineData && isset($inlineData['data'])) {
                    $mimeType = $inlineData['mime_type'] ?? $inlineData['mimeType'] ?? 'image/png';
                    $images[] = [
                        'url' => 'data:'.$mimeType.';base64,'.$inlineData['data'],
                        'revised_prompt' => $prompt,
                    ];
                }
            }

            return $images;
        } catch (\Exception $e) {
            throw new ProviderException('Google Gemini image generation error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Generate image using Imagen via Vertex AI.
     */
    private function generateImageWithImagen(string $model, string $prompt, array $options): array
    {
        try {
            if (!$this->projectId) {
                throw new ProviderException('Google project ID required for Imagen image generation', 'google');
            }

            $url = str_replace('{region}', $this->region, self::VERTEX_BASE)
                ."/projects/{$this->projectId}/locations/{$this->region}"
                ."/publishers/google/models/{$model}:predict";

            $payload = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                    ],
                ],
                'parameters' => [
                    'sampleCount' => $options['n'] ?? 1,
                    'aspectRatio' => $options['aspect_ratio'] ?? '1:1',
                    'negativePrompt' => $options['negative_prompt'] ?? '',
                    'personGeneration' => 'allow_all',
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Authorization' => 'Bearer '.$this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            $images = [];
            foreach ($data['predictions'] ?? [] as $prediction) {
                if (isset($prediction['bytesBase64Encoded'])) {
                    $images[] = [
                        'url' => 'data:image/png;base64,'.$prediction['bytesBase64Encoded'],
                        'revised_prompt' => $prompt,
                    ];
                }
            }

            return $images;
        } catch (\Exception $e) {
            throw new ProviderException('Google Imagen generation error: '.$e->getMessage(), 'google');
        }
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Image variations not supported by Google Imagen', 'google');
    }

    // ==================== VIDEO GENERATION ====================

    public function generateVideo(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'veo-3.1-generate-preview';

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            // Map requested duration to valid Veo values (4, 6, or 8 seconds)
            $requestedDuration = isset($options['duration']) && is_numeric($options['duration'])
                ? (int) $options['duration']
                : 8;
            $durationSeconds = $this->mapToValidVeoDuration($requestedDuration);
            $aspectRatio = $options['aspect_ratio'] ?? '16:9';

            $this->logger->info('Google Veo: Starting video generation', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
                'requested_duration' => $requestedDuration,
                'actual_duration' => $durationSeconds,
                'aspect_ratio' => $aspectRatio,
            ]);

            // Use Gemini API (not Vertex AI!) - predictLongRunning endpoint for async operation
            $url = self::API_BASE."/models/{$model}:predictLongRunning";

            $payload = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                    ],
                ],
                'parameters' => [
                    'durationSeconds' => $durationSeconds,
                    'aspectRatio' => $aspectRatio,
                ],
            ];

            $this->logger->info('Google Veo: Sending video generation request', [
                'url' => $url,
                'model' => $model,
            ]);

            // Start the long-running operation
            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey, // Use x-goog-api-key header, NOT Authorization
                ],
                'json' => $payload,
                'timeout' => 30,
            ]);

            // Check status code BEFORE calling toArray()
            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $errorBody = $response->getContent(false); // false = don't throw on error
                $this->logger->error('Google Veo: API returned error', [
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                    'url' => $url,
                    'payload' => json_encode($payload),
                ]);
                throw new \Exception("Google Veo API error (HTTP $statusCode): $errorBody");
            }

            $data = $response->toArray();

            $this->logger->info('Google Veo: Response received', [
                'response' => json_encode($data),
            ]);

            // Get the operation name
            $operationName = $data['name'] ?? null;
            if (!$operationName) {
                throw new \Exception('No operation name returned from Google Veo');
            }

            $this->logger->info('Google Veo: Operation started', [
                'operation' => $operationName,
            ]);

            // Poll the operation until it's done (max 5 minutes polling)
            $maxAttempts = 60; // 60 attempts * 5 seconds = 300 seconds (5 minutes)
            $attempt = 0;
            $operationUrl = self::API_BASE.'/'.$operationName;

            while ($attempt < $maxAttempts) {
                sleep(5); // Wait 5 seconds between polls
                ++$attempt;

                $this->logger->info('Google Veo: Polling operation', [
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                ]);

                $statusResponse = $this->httpClient->request('GET', $operationUrl, [
                    'headers' => [
                        'x-goog-api-key' => $this->apiKey,
                    ],
                    'timeout' => 30,
                ]);

                $statusData = $statusResponse->toArray();

                if (isset($statusData['done']) && true === $statusData['done']) {
                    // Operation completed!
                    $this->logger->info('Google Veo: Video generation completed!');

                    // Extract video URI
                    $videoUri = $statusData['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri'] ?? null;

                    if (!$videoUri) {
                        throw new \Exception('No video URI in completed operation response');
                    }

                    // Download the video from the URI
                    $videoResponse = $this->httpClient->request('GET', $videoUri, [
                        'headers' => [
                            'x-goog-api-key' => $this->apiKey,
                        ],
                        'timeout' => 120,
                    ]);

                    $videoData = $videoResponse->getContent();

                    // Convert to base64 data URL
                    $base64Video = base64_encode($videoData);

                    return [[
                        'url' => 'data:video/mp4;base64,'.$base64Video,
                        'revised_prompt' => $prompt,
                        'duration' => $durationSeconds,
                    ]];
                }
            }

            throw new \Exception('Video generation timed out after '.($maxAttempts * 5).' seconds');
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
            // Log the full error response
            $this->logger->error('Google Veo: HTTP Error', [
                'status_code' => $e->getResponse()->getStatusCode(),
                'response_body' => $e->getResponse()->getContent(false),
                'url' => $e->getResponse()->getInfo('url') ?? 'unknown',
            ]);

            throw new ProviderException('Google video generation error: '.$e->getMessage(), 'google');
        } catch (\Exception $e) {
            $this->logger->error('Google Veo: General Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ProviderException('Google video generation error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Map requested duration to valid Veo duration values.
     *
     * Google Veo 3.1 only supports 4, 6, or 8 seconds.
     * This method maps any requested duration to the closest valid value.
     */
    private function mapToValidVeoDuration(int|float $requestedDuration): int
    {
        $duration = (int) round($requestedDuration);

        // Veo 3.1 valid values: 4, 6, 8
        if ($duration <= 5) {
            return 4;
        }
        if ($duration <= 7) {
            return 6;
        }

        return 8;
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        // Google Gemini 2.5 Flash Image supports editing
        $model = 'gemini-2.5-flash-image-preview';

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            // Read the image
            $fullPath = $this->uploadDir.'/'.ltrim($imageUrl, '/');
            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            $url = self::API_BASE."/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => "Edit this image: {$prompt}",
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            // Extract image from response
            if (isset($data['candidates'][0]['content']['parts'][0]['inline_data'])) {
                $imageBase64 = $data['candidates'][0]['content']['parts'][0]['inline_data']['data'];

                return 'data:image/png;base64,'.$imageBase64;
            }

            throw new \Exception('No image returned from Google');
        } catch (\Exception $e) {
            throw new ProviderException('Google image edit error: '.$e->getMessage(), 'google');
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $defaultPrompt = 'Describe what you see in this image in detail.';

        return $this->analyzeImage($imageUrl, $prompt ?: $defaultPrompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->analyzeImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $fullPath1 = $this->uploadDir.'/'.ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir.'/'.ltrim($imageUrl2, '/');

            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception('One or both images not found');
            }

            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $model = 'gemini-2.5-pro';
            $url = self::API_BASE."/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => 'Compare these two images and describe the similarities and differences.',
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType1,
                                    'data' => $base64Image1,
                                ],
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType2,
                                    'data' => $base64Image2,
                                ],
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();
            $comparison = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';

            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException('Google image comparison error: '.$e->getMessage(), 'google');
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $model = $options['model'] ?? 'gemini-2.5-pro';

            $fullPath = $this->uploadDir.'/'.ltrim($imagePath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            $this->logger->info('Google: Analyzing image', [
                'model' => $model,
                'image' => basename($imagePath),
                'prompt_length' => strlen($prompt),
            ]);

            $url = self::API_BASE."/models/{$model}:generateContent";

            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $prompt,
                            ],
                            [
                                'inline_data' => [
                                    'mime_type' => $mimeType,
                                    'data' => $base64Image,
                                ],
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'maxOutputTokens' => $options['max_tokens'] ?? 1000,
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException('Google vision error: '.$e->getMessage(), 'google');
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        try {
            $allowedTtsModels = [
                'gemini-2.5-flash-preview-tts',
                'gemini-2.5-pro-preview-tts',
            ];
            $model = $options['model'] ?? 'gemini-2.5-flash-preview-tts';
            if (!in_array($model, $allowedTtsModels, true)) {
                $model = 'gemini-2.5-flash-preview-tts';
            }
            $voiceName = $options['voice'] ?? 'Kore';

            $this->logger->info('Google Gemini: Synthesizing speech with multimodal output', [
                'model' => $model,
                'text_length' => strlen($text),
                'voice' => $voiceName,
            ]);

            // Use Gemini 2.0 Flash with TTS capability (audio output modality)
            $url = self::API_BASE."/models/{$model}:generateContent";

            // Gemini 2.0 Flash TTS via speech modality
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $text,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'], // Request audio output
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => $voiceName,
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 240,
                'max_duration' => 300,
            ]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $errorBody = $response->getContent(false);
                $this->logger->error('Google Gemini TTS: API error', [
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                ]);
                throw new \Exception("Google Gemini TTS API error (HTTP $statusCode): $errorBody");
            }

            $data = $response->toArray();

            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $audioPart = null;
            foreach ($parts as $part) {
                if (isset($part['inline_data']['data'])) {
                    $this->logger->info('Google Gemini TTS: Found inline_data audio part', [
                        'keys' => array_keys($part['inline_data']),
                        'mime_type' => $part['inline_data']['mime_type'] ?? null,
                        'data_preview' => substr($part['inline_data']['data'], 0, 32),
                    ]);
                    $audioPart = $part['inline_data'];
                    break;
                }
                if (isset($part['inlineData']['data'])) {
                    $this->logger->info('Google Gemini TTS: Found inlineData audio part', [
                        'keys' => array_keys($part['inlineData']),
                        'mime_type' => $part['inlineData']['mimeType'] ?? null,
                        'data_preview' => substr($part['inlineData']['data'], 0, 32),
                    ]);
                    $audioPart = $part['inlineData'];
                    break;
                }
            }

            if (!$audioPart || empty($audioPart['data'])) {
                $this->logger->error('Google Gemini TTS: No audio data in response', [
                    'response' => json_encode($data),
                ]);
                throw new \Exception('No audio data returned from Gemini TTS');
            }

            $base64Audio = $audioPart['data'];
            $mimeType = strtolower($audioPart['mime_type'] ?? $audioPart['mimeType'] ?? 'audio/wav');

            // Decode base64 audio
            $audioData = base64_decode($base64Audio);

            if (false === $audioData || '' === $audioData) {
                throw new \Exception('Failed to decode audio data returned from Gemini TTS');
            }

            // Gemini TTS returns signed 16-bit PCM data by default (see docs)
            // Convert PCM payload to a proper WAV container so browsers can play it
            if ($this->isRawPcmMimeType($mimeType)) {
                $audioData = $this->convertPcmToWav($audioData);
                $mimeType = 'audio/wav';
            }

            // Determine file extension from mime type (after PCM conversion)
            $extension = match (true) {
                str_contains($mimeType, 'wav') => 'wav',
                str_contains($mimeType, 'mp3') => 'mp3',
                str_contains($mimeType, 'ogg') => 'ogg',
                default => 'wav',
            };

            // Save to file with proper permissions
            $this->ensureUploadDirectory();
            $filename = 'tts_'.uniqid().'.'.$extension;
            $outputPath = rtrim($this->uploadDir, '/\\').'/'.$filename;

            $written = FileHelper::writeFile($outputPath, $audioData);
            if (false === $written) {
                throw new \Exception("Failed to write audio file to {$outputPath}");
            }

            $this->logger->info('Google Gemini TTS: Audio saved', [
                'filename' => $filename,
                'size_bytes' => strlen($audioData),
                'mime_type' => $mimeType,
            ]);

            return $filename;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google TTS error: '.$e->getMessage(), 'google');
        }
    }

    private function ensureUploadDirectory(): void
    {
        if (!FileHelper::createDirectory($this->uploadDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $this->uploadDir));
        }

        if (!is_writable($this->uploadDir)) {
            throw new \RuntimeException(sprintf('Upload directory is not writable: %s', $this->uploadDir));
        }
    }

    public function getVoices(): array
    {
        // Google Cloud TTS voices would be loaded here
        return [];
    }

    private function isRawPcmMimeType(?string $mimeType): bool
    {
        if (!$mimeType) {
            return true;
        }

        return str_contains($mimeType, 'pcm')
            || str_contains($mimeType, 'x-raw')
            || str_contains($mimeType, 'linear16');
    }

    /**
     * Gemini TTS returns signed 16-bit PCM buffers (24 kHz, mono) by default.
     * Wrap the PCM payload into a RIFF/WAVE container so browsers can play it.
     */
    private function convertPcmToWav(
        string $pcmData,
        int $sampleRate = 24000,
        int $channels = 1,
        int $bitsPerSample = 16,
    ): string {
        $dataSize = strlen($pcmData);
        $blockAlign = (int) ($channels * ($bitsPerSample / 8));
        $byteRate = $sampleRate * $blockAlign;

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1) // PCM format
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize);

        return $header.$pcmData;
    }

    // ==================== HELPER METHODS ====================

    /**
     * Convert OpenAI-style messages to Gemini format.
     */
    private function convertMessagesToGeminiFormat(array $messages): array
    {
        $contents = [];

        foreach ($messages as $message) {
            $role = 'assistant' === $message['role'] ? 'model' : 'user';

            $parts = [];
            if (is_string($message['content'])) {
                $parts[] = ['text' => $message['content']];
            } elseif (is_array($message['content'])) {
                foreach ($message['content'] as $part) {
                    if ('text' === $part['type']) {
                        $parts[] = ['text' => $part['text']];
                    } elseif ('image_url' === $part['type']) {
                        $imageUrl = $part['image_url']['url'] ?? $part['image_url'];
                        if (str_starts_with($imageUrl, 'data:image')) {
                            // Base64 image
                            list($mime, $data) = explode(';', $imageUrl);
                            list(, $data) = explode(',', $data);
                            $parts[] = [
                                'inline_data' => [
                                    'mime_type' => str_replace('data:', '', $mime),
                                    'data' => $data,
                                ],
                            ];
                        }
                    }
                }
            }

            $contents[] = [
                'role' => $role,
                'parts' => $parts,
            ];
        }

        return $contents;
    }
}
