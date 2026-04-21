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
 * - Imagen 4 (Gemini API), Gemini native image models, optional Vertex Imagen with OAuth token
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
        private ?string $vertexAccessToken = null,
    ) {
        // Ensure projectId is null if empty string
        if (empty($this->projectId)) {
            $this->projectId = null;
        }
        if (empty($this->vertexAccessToken)) {
            $this->vertexAccessToken = null;
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
                'any_of' => ['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'],
                'hint' => 'Get your API key from https://aistudio.google.com/app/apikey',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
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
                    'maxOutputTokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
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

            $this->checkGeminiFinishReason($data);

            $usageMetadata = $data['usageMetadata'] ?? [];
            $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
            $completionTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
            $cachedTokens = $usageMetadata['cachedContentTokenCount'] ?? 0;

            $usage = [
                'prompt_tokens' => $promptTokens,
                'completion_tokens' => $completionTokens,
                'total_tokens' => $promptTokens + $completionTokens,
                'cached_tokens' => $cachedTokens,
                'cache_creation_tokens' => 0,
            ];

            $textContent = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && empty($part['thought'])) {
                    $textContent .= $part['text'];
                }
            }

            return [
                'content' => $textContent,
                'usage' => $usage,
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google chat error: '.$e->getMessage(), 'google');
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
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
                    'maxOutputTokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
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

            $usage = [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
                'cached_tokens' => 0,
                'cache_creation_tokens' => 0,
            ];
            $finishReason = null;

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isLast()) {
                    break;
                }

                $content = $chunk->getContent();

                // Parse SSE format: data: {...}
                if (str_starts_with($content, 'data: ')) {
                    $jsonData = substr($content, 6);
                    $data = json_decode($jsonData, true);

                    if ($data) {
                        $this->checkGeminiFinishReason($data);

                        // Capture finishReason from the last chunk (Gemini uses "MAX_TOKENS" or "STOP")
                        $geminiFinishReason = $data['candidates'][0]['finishReason'] ?? null;
                        if (null !== $geminiFinishReason) {
                            $finishReason = match ($geminiFinishReason) {
                                'MAX_TOKENS' => 'length',
                                'STOP', 'END_TURN' => 'stop',
                                default => $geminiFinishReason,
                            };
                        }
                    }

                    // Capture usage from each chunk (last chunk has final values)
                    if (isset($data['usageMetadata'])) {
                        $usageMetadata = $data['usageMetadata'];
                        $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
                        $completionTokens = $usageMetadata['candidatesTokenCount'] ?? 0;
                        $cachedTokens = $usageMetadata['cachedContentTokenCount'] ?? 0;
                        $usage = [
                            'prompt_tokens' => $promptTokens,
                            'completion_tokens' => $completionTokens,
                            'total_tokens' => $promptTokens + $completionTokens,
                            'cached_tokens' => $cachedTokens,
                            'cache_creation_tokens' => 0,
                        ];
                    }

                    $parts = $data['candidates'][0]['content']['parts'] ?? [];
                    foreach ($parts as $part) {
                        if (!isset($part['text']) || '' === $part['text']) {
                            continue;
                        }

                        if (!empty($part['thought'])) {
                            $callback(['type' => 'reasoning', 'content' => $part['text']]);
                        } else {
                            $callback($part['text']);
                        }
                    }
                }
            }

            if (null !== $finishReason) {
                $callback(['type' => 'finish', 'finish_reason' => $finishReason]);
            }

            return ['usage' => $usage];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google streaming error: '.$e->getMessage(), 'google');
        }
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'imagen-4.0-generate-001';
        $inputImages = $options['images'] ?? [];

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        // Pic2pic: when input images are provided and model is a Gemini image model
        if (!empty($inputImages) && $this->isGeminiNativeImageModel($model)) {
            return $this->generateImageFromImagesWithGemini($model, $prompt, $inputImages, $options);
        }

        // Determine API type: explicit config > auto-detect from model name
        $modelConfig = $options['modelConfig'] ?? [];
        $apiType = $modelConfig['api'] ?? null;

        if (!$apiType) {
            $apiType = $this->isGeminiNativeImageModel($model) ? 'gemini_native' : 'imagen';
        }

        $this->logger->info('Google: Generating image', [
            'model' => $model,
            'api_type' => $apiType,
            'prompt_length' => strlen($prompt),
        ]);

        return match ($apiType) {
            'gemini', 'gemini_native' => $this->generateImageWithGemini($model, $prompt, $options),
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

            $this->checkGeminiFinishReason($data);

            $parts = $data['candidates'][0]['content']['parts'] ?? [];

            $images = [];
            foreach ($parts as $part) {
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
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google Gemini image generation error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Pic2pic: generate an image from input images + text using Gemini native API.
     *
     * @param string   $model      Gemini image model (e.g. gemini-3.1-flash-image-preview)
     * @param string   $prompt     Text instruction
     * @param string[] $imagePaths Absolute paths to input images
     *
     * @see https://ai.google.dev/gemini-api/docs/image-generation
     */
    private function generateImageFromImagesWithGemini(string $model, string $prompt, array $imagePaths, array $options): array
    {
        try {
            $url = self::API_BASE."/models/{$model}:generateContent";

            $parts = [['text' => $prompt]];
            foreach ($imagePaths as $imgPath) {
                $data = file_get_contents($imgPath);
                if (false === $data) {
                    throw new \Exception('Failed to read image: '.basename($imgPath));
                }
                $mimeType = mime_content_type($imgPath) ?: 'image/png';
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $mimeType,
                        'data' => base64_encode($data),
                    ],
                ];
            }

            $payload = [
                'contents' => [['parts' => $parts]],
                'generationConfig' => [
                    'responseModalities' => ['TEXT', 'IMAGE'],
                ],
            ];

            if (!empty($options['aspect_ratio'])) {
                $payload['generationConfig']['imageConfig'] = [
                    'aspectRatio' => $options['aspect_ratio'],
                ];
            }

            $this->logger->info('Google: Pic2pic via Gemini native API', [
                'model' => $model,
                'image_count' => \count($imagePaths),
                'prompt_length' => \strlen($prompt),
            ]);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $this->apiKey,
                ],
                'json' => $payload,
                'timeout' => 120,
            ]);

            $data = $response->toArray();

            $this->checkGeminiFinishReason($data);

            $responseParts = $data['candidates'][0]['content']['parts'] ?? [];

            $images = [];
            foreach ($responseParts as $part) {
                $inlineData = $part['inline_data'] ?? $part['inlineData'] ?? null;
                if ($inlineData && isset($inlineData['data'])) {
                    $mime = $inlineData['mime_type'] ?? $inlineData['mimeType'] ?? 'image/png';
                    $images[] = [
                        'url' => 'data:'.$mime.';base64,'.$inlineData['data'],
                        'revised_prompt' => $prompt,
                    ];
                }
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google Gemini pic2pic error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Check Gemini generateContent response for blocked content.
     *
     * Gemini returns finishReason codes like SAFETY, RECITATION, PROHIBITED_CONTENT
     * when it refuses to generate. The HTTP status is still 200, but the response
     * contains no usable content parts.
     *
     * @throws ProviderException when content was blocked
     */
    private function checkGeminiFinishReason(array $data): void
    {
        $candidate = $data['candidates'][0] ?? null;

        if (!$candidate) {
            $blockReason = $data['promptFeedback']['blockReason'] ?? null;
            if ($blockReason) {
                $this->logger->warning('Google Gemini: Prompt blocked', [
                    'block_reason' => $blockReason,
                    'prompt_feedback' => $data['promptFeedback'] ?? null,
                ]);
                throw ProviderException::contentBlocked('google', $blockReason);
            }

            return;
        }

        $finishReason = $candidate['finishReason'] ?? null;

        if ($finishReason && !\in_array($finishReason, ['STOP', 'MAX_TOKENS', 'END_TURN'], true)) {
            $textResponse = null;
            $parts = $candidate['content']['parts'] ?? [];
            foreach ($parts as $part) {
                if (isset($part['text'])) {
                    $textResponse = $part['text'];
                    break;
                }
            }

            $this->logger->warning('Google Gemini: Content blocked', [
                'finish_reason' => $finishReason,
                'safety_ratings' => $candidate['safetyRatings'] ?? null,
                'text_response' => $textResponse ? substr($textResponse, 0, 300) : null,
            ]);

            throw ProviderException::contentBlocked('google', $finishReason, $textResponse);
        }
    }

    /**
     * Generate image using Imagen via Gemini API (API key) or Vertex AI (project ID + OAuth).
     *
     * Gemini API: available for imagen-4.0-generate-001
     *
     * @see https://ai.google.dev/gemini-api/docs/imagen
     */
    private function generateImageWithImagen(string $model, string $prompt, array $options): array
    {
        try {
            $payload = [
                'instances' => [
                    [
                        'prompt' => $prompt,
                    ],
                ],
                'parameters' => [
                    'sampleCount' => $options['n'] ?? 1,
                    'aspectRatio' => $options['aspect_ratio'] ?? '1:1',
                ],
            ];

            // Imagen 4+ is supported on the Gemini API with GOOGLE_GEMINI_API_KEY (see Google docs).
            // Vertex AI requires a separate OAuth access token — never send the API key as Bearer.
            if ($this->projectId && $this->vertexAccessToken) {
                return $this->generateImageWithImagenVertex($model, $payload);
            }

            return $this->generateImageWithImagenGemini($model, $payload);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google Imagen generation error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Imagen via Gemini API (API key auth, no GCP project required).
     */
    private function generateImageWithImagenGemini(string $model, array $payload): array
    {
        $url = self::API_BASE."/models/{$model}:predict";

        $this->logger->info('Google Imagen: Using Gemini API', [
            'model' => $model,
            'url' => $url,
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ],
            'json' => $payload,
            'timeout' => 120,
        ]);

        return $this->parseImagenResponse($response->toArray());
    }

    /**
     * Imagen via Vertex AI (GCP project + OAuth2 access token from GOOGLE_VERTEX_ACCESS_TOKEN).
     */
    private function generateImageWithImagenVertex(string $model, array $payload): array
    {
        if (!$this->vertexAccessToken) {
            throw new ProviderException('Vertex Imagen requires GOOGLE_VERTEX_ACCESS_TOKEN (OAuth bearer), not the Gemini API key', 'google');
        }

        $url = str_replace('{region}', $this->region, self::VERTEX_BASE)
            ."/projects/{$this->projectId}/locations/{$this->region}"
            ."/publishers/google/models/{$model}:predict";

        $this->logger->info('Google Imagen: Using Vertex AI', [
            'model' => $model,
            'project' => $this->projectId,
        ]);

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer '.$this->vertexAccessToken,
            ],
            'json' => $payload,
            'timeout' => 120,
        ]);

        return $this->parseImagenResponse($response->toArray());
    }

    private function parseImagenResponse(array $data): array
    {
        $images = [];
        foreach ($data['predictions'] ?? [] as $prediction) {
            if (isset($prediction['bytesBase64Encoded'])) {
                $mimeType = $prediction['mimeType'] ?? 'image/png';
                $images[] = [
                    'url' => 'data:'.$mimeType.';base64,'.$prediction['bytesBase64Encoded'],
                    'revised_prompt' => null,
                ];
            }
        }

        return $images;
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Image variations not supported by Google Imagen', 'google');
    }

    // ==================== VIDEO GENERATION ====================

    public function generateVideo(string $prompt, array $options = []): array
    {
        try {
            $operationData = $this->startVideoOperation($prompt, $options);
            $progressCallback = $options['progress_callback'] ?? null;

            $videoUri = $this->pollVideoUntilComplete(
                $operationData['operationName'],
                $progressCallback,
            );

            $videoDataUrl = $this->downloadVideoContent($videoUri);

            return [[
                'url' => $videoDataUrl,
                'revised_prompt' => $prompt,
                'duration' => $operationData['duration'],
            ]];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface $e) {
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
     * Start a Veo video generation operation without blocking.
     *
     * @return array{operationName: string, model: string, duration: int}
     */
    public function startVideoOperation(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'veo-3.1-generate-preview';

        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

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

        $url = self::API_BASE."/models/{$model}:predictLongRunning";

        $payload = [
            'instances' => [
                ['prompt' => $prompt],
            ],
            'parameters' => [
                'durationSeconds' => $durationSeconds,
                'aspectRatio' => $aspectRatio,
            ],
        ];

        $response = $this->httpClient->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'x-goog-api-key' => $this->apiKey,
            ],
            'json' => $payload,
            'timeout' => 30,
        ]);

        $statusCode = $response->getStatusCode();
        if (200 !== $statusCode) {
            $errorBody = $response->getContent(false);
            $this->logger->error('Google Veo: API returned error', [
                'status_code' => $statusCode,
                'error_body' => $errorBody,
                'url' => $url,
            ]);
            throw new ProviderException("Google Veo API error (HTTP $statusCode): $errorBody", 'google');
        }

        $data = $response->toArray();
        $operationName = $data['name'] ?? null;
        if (!$operationName) {
            throw new ProviderException('No operation name returned from Google Veo', 'google');
        }

        $this->logger->info('Google Veo: Operation started', [
            'operation' => $operationName,
        ]);

        return [
            'operationName' => $operationName,
            'model' => $model,
            'duration' => $durationSeconds,
        ];
    }

    /**
     * Poll a Veo operation once and return its current status.
     *
     * @return array{done: bool, videoUri: ?string, error: ?string}
     */
    public function pollVideoOperationOnce(string $operationName): array
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        $operationUrl = self::API_BASE.'/'.$operationName;

        $statusResponse = $this->httpClient->request('GET', $operationUrl, [
            'headers' => ['x-goog-api-key' => $this->apiKey],
            'timeout' => 30,
        ]);

        $statusData = $statusResponse->toArray();

        if (!isset($statusData['done']) || true !== $statusData['done']) {
            return ['done' => false, 'videoUri' => null, 'error' => null];
        }

        if (isset($statusData['error'])) {
            $errorMessage = $statusData['error']['message'] ?? 'Unknown error';
            $errorCode = $statusData['error']['code'] ?? 'UNKNOWN';

            $this->logger->error('Google Veo: Operation failed', [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            if (str_contains(strtolower($errorMessage), 'safety') || str_contains(strtolower($errorMessage), 'blocked')) {
                throw ProviderException::contentBlocked('google', 'SAFETY', $errorMessage);
            }

            return ['done' => true, 'videoUri' => null, 'error' => $errorMessage.' (code: '.$errorCode.')'];
        }

        $videoUri = $statusData['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri']
            ?? $statusData['response']['generatedVideos'][0]['video']['uri']
            ?? null;

        if (!$videoUri) {
            $this->logger->error('Google Veo: No video URI found in response', [
                'response_keys' => array_keys($statusData['response'] ?? []),
                'full_response' => json_encode($statusData, JSON_PRETTY_PRINT),
            ]);

            return ['done' => true, 'videoUri' => null, 'error' => 'No video URI in completed operation response'];
        }

        $this->logger->info('Google Veo: Video generation completed!');

        return ['done' => true, 'videoUri' => $videoUri, 'error' => null];
    }

    /**
     * Download video from a Google-provided URI and return as data URL.
     */
    public function downloadVideoContent(string $videoUri): string
    {
        $raw = $this->downloadVideoRaw($videoUri);

        return 'data:video/mp4;base64,'.base64_encode($raw);
    }

    /**
     * Download raw video bytes from a Google-provided URI.
     */
    public function downloadVideoRaw(string $videoUri): string
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('google', 'GOOGLE_GEMINI_API_KEY');
        }

        $videoResponse = $this->httpClient->request('GET', $videoUri, [
            'headers' => ['x-goog-api-key' => $this->apiKey],
            'timeout' => 120,
        ]);

        return $videoResponse->getContent();
    }

    /**
     * Poll until the Veo operation completes, calling progressCallback each iteration.
     *
     * @return string The video URI to download
     */
    private function pollVideoUntilComplete(string $operationName, ?callable $progressCallback): string
    {
        $maxAttempts = 60;
        $startTime = time();

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            sleep(5);

            $elapsed = time() - $startTime;

            if ($progressCallback) {
                $progressCallback([
                    'status' => 'polling',
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'elapsed_seconds' => $elapsed,
                ]);
            }

            $this->logger->info('Google Veo: Polling operation', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'elapsed' => $elapsed,
            ]);

            $result = $this->pollVideoOperationOnce($operationName);

            if (!$result['done']) {
                continue;
            }

            if ($result['error']) {
                throw new ProviderException('Google video generation failed: '.$result['error'], 'google');
            }

            return $result['videoUri'];
        }

        throw new ProviderException('Video generation timed out after '.($maxAttempts * 5).' seconds', 'google');
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
                    // Gemini 2.5 Pro uses ~1000 tokens for "thinking", so we need higher limit
                    // to leave room for actual text output (especially for OCR tasks)
                    'maxOutputTokens' => $options['max_tokens'] ?? 8192,
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

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $filename = $this->synthesize($text, $options);
        $fullPath = $this->uploadDir.'/'.$filename;

        if (!file_exists($fullPath)) {
            throw new ProviderException('Google TTS: synthesized file not found', 'google');
        }

        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new ProviderException('Google TTS: cannot read synthesized file', 'google');
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 8192);
                if (false !== $chunk && '' !== $chunk) {
                    yield $chunk;
                }
            }
        } finally {
            fclose($handle);
            @unlink($fullPath);
        }
    }

    public function getStreamContentType(array $options = []): string
    {
        return 'audio/wav';
    }

    public function supportsStreaming(): bool
    {
        return false;
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
