<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * HuggingFace Inference Providers - Unified API for 200k+ models.
 *
 * Uses single HF token to access multiple backend providers (Fal AI, SambaNova, Groq, etc.)
 * through the router.huggingface.co proxy.
 *
 * Supports:
 * - Chat (OpenAI-compatible endpoint)
 * - Image Generation (text-to-image)
 * - Video Generation (text-to-video)
 * - Embeddings (feature extraction)
 *
 * @see https://huggingface.co/docs/inference-providers/index
 */
class HuggingFaceProvider implements ChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VideoGenerationProviderInterface
{
    private const CHAT_ENDPOINT = 'https://router.huggingface.co/v1/chat/completions';
    private const ROUTER_BASE = 'https://router.huggingface.co';
    private const BILLING_URL = 'https://huggingface.co/settings/billing';
    private const DEFAULT_VIDEO_INFERENCE_STEPS = 30;
    private const QUEUE_POLL_INTERVAL_SECONDS = 3;
    private const QUEUE_MAX_POLL_ATTEMPTS = 180;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function getName(): string
    {
        return 'huggingface';
    }

    public function getDisplayName(): string
    {
        return 'HuggingFace';
    }

    public function getDescription(): string
    {
        return 'Unified API for 200k+ models via HuggingFace Inference Providers';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'image_generation', 'video_generation'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (empty($this->apiKey)) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            // TODO: Replace placeholder metrics with real health monitoring data
            //       (latency, error rate, active connections) or remove these fields.
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
            'HUGGINGFACE_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API token from https://huggingface.co/settings/tokens',
            ],
        ];
    }

    // ==================== CHAT (OpenAI-compatible) ====================

    public function chat(array $messages, array $options = []): string
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'huggingface');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            $model = $this->buildModelString($options['model'], $options);

            $this->logger->info('HuggingFace chat request', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'stream' => false,
            ];

            if (isset($options['max_tokens'])) {
                $requestBody['max_tokens'] = $options['max_tokens'];
            }

            if (isset($options['temperature'])) {
                $requestBody['temperature'] = $options['temperature'];
            }

            $response = $this->httpClient->request('POST', self::CHAT_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
                'timeout' => 120,
            ]);

            $data = $response->toArray();

            return $data['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace chat error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'huggingface');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            $model = $this->buildModelString($options['model'], $options);

            $this->logger->info('HuggingFace streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $requestBody = [
                'model' => $model,
                'messages' => $messages,
                'stream' => true,
            ];

            if (isset($options['max_tokens'])) {
                $requestBody['max_tokens'] = $options['max_tokens'];
            }

            if (isset($options['temperature'])) {
                $requestBody['temperature'] = $options['temperature'];
            }

            $response = $this->httpClient->request('POST', self::CHAT_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'text/event-stream',
                ],
                'json' => $requestBody,
                'timeout' => 120,
            ]);

            $chunkCount = 0;

            // Process Server-Sent Events stream
            foreach ($this->httpClient->stream($response) as $chunk) {
                $content = $chunk->getContent();

                foreach (explode("\n", $content) as $line) {
                    $line = trim($line);
                    if (empty($line) || !str_starts_with($line, 'data: ')) {
                        continue;
                    }

                    $data = substr($line, 6);

                    if ('[DONE]' === $data) {
                        break;
                    }

                    $json = json_decode($data, true);
                    if (null === $json) {
                        continue;
                    }

                    $delta = $json['choices'][0]['delta']['content'] ?? '';
                    if (!empty($delta)) {
                        ++$chunkCount;
                        $callback($delta);
                    }
                }
            }

            $this->logger->info('HuggingFace streaming COMPLETE', [
                'model' => $model,
                'chunks' => $chunkCount,
            ]);
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace streaming error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace streaming error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    // ==================== EMBEDDINGS ====================

    public function embed(string $text, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'huggingface');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            $model = $options['model'];

            // Map Synaplan provider name to HuggingFace API provider
            $provider = $options['provider'] ?? 'hf-inference';
            if ('huggingface' === $provider || 'HuggingFace' === $provider) {
                $provider = 'hf-inference';
            }

            $this->logger->info('HuggingFace embedding request', [
                'model' => $model,
                'text_length' => strlen($text),
            ]);

            $url = self::ROUTER_BASE."/{$provider}/models/{$model}";

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $text,
                    'normalize' => true,
                ],
                'timeout' => 60,
            ]);

            $data = $response->toArray();

            // Response is either nested array [[...]] or flat array [...]
            if (isset($data[0]) && is_array($data[0])) {
                return $data[0];
            }

            return $data;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace embedding error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace embedding error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'huggingface');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            $model = $options['model'];

            // Map Synaplan provider name to HuggingFace API provider
            $provider = $options['provider'] ?? 'hf-inference';
            if ('huggingface' === $provider || 'HuggingFace' === $provider) {
                $provider = 'hf-inference';
            }

            $this->logger->info('HuggingFace batch embedding request', [
                'model' => $model,
                'batch_size' => count($texts),
            ]);

            $url = self::ROUTER_BASE."/{$provider}/models/{$model}";

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $texts,
                    'normalize' => true,
                ],
                'timeout' => 120,
            ]);

            return $response->toArray();
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace batch embedding error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace batch embedding error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    public function getDimensions(string $model): int
    {
        // Common HuggingFace embedding model dimensions
        $dimensions = [
            'intfloat/multilingual-e5-large' => 1024,
            'thenlper/gte-large' => 1024,
            'BAAI/bge-m3' => 1024,
            'sentence-transformers/all-MiniLM-L6-v2' => 384,
        ];

        return $dimensions[$model] ?? 768;
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'huggingface');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            $model = $options['model'];

            // Map Synaplan provider name to HuggingFace API provider
            // If 'provider' is 'huggingface' (Synaplan service name) or not set, use 'hf-inference'
            $provider = $options['provider'] ?? 'hf-inference';
            if ('huggingface' === $provider || 'HuggingFace' === $provider) {
                $provider = 'hf-inference';
            }

            $this->logger->info('HuggingFace image generation request', [
                'model' => $model,
                'provider' => $provider,
                'prompt_length' => strlen($prompt),
            ]);

            $url = self::ROUTER_BASE."/{$provider}/models/{$model}";

            $requestBody = [
                'inputs' => $prompt,
            ];

            // Add optional parameters
            $parameters = [];
            if (isset($options['width'])) {
                $parameters['width'] = (int) $options['width'];
            }
            if (isset($options['height'])) {
                $parameters['height'] = (int) $options['height'];
            }
            if (isset($options['guidance_scale'])) {
                $parameters['guidance_scale'] = (float) $options['guidance_scale'];
            }
            if (isset($options['num_inference_steps'])) {
                $parameters['num_inference_steps'] = (int) $options['num_inference_steps'];
            }
            if (isset($options['negative_prompt'])) {
                $parameters['negative_prompt'] = $options['negative_prompt'];
            }
            if (isset($options['seed'])) {
                $parameters['seed'] = (int) $options['seed'];
            }

            if (!empty($parameters)) {
                $requestBody['parameters'] = $parameters;
            }

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
                'timeout' => 180, // Image generation can be slow
            ]);

            $statusCode = $response->getStatusCode();

            // Check for payment required error
            if (402 === $statusCode) {
                throw new ProviderException(sprintf('HuggingFace image generation requires prepaid credits. Add credits at %s', self::BILLING_URL), 'huggingface');
            }

            // Response is binary image data
            $imageData = $response->getContent();
            $contentType = $response->getHeaders()['content-type'][0] ?? 'image/png';

            // Normalize content type for data URL
            $mimeType = 'image/png';
            if (str_contains($contentType, 'jpeg') || str_contains($contentType, 'jpg')) {
                $mimeType = 'image/jpeg';
            } elseif (str_contains($contentType, 'webp')) {
                $mimeType = 'image/webp';
            }

            // Convert to base64 data URL - MediaGenerationHandler will save it to user's path
            $base64 = base64_encode($imageData);
            $dataUrl = "data:{$mimeType};base64,{$base64}";

            $this->logger->info('HuggingFace image generated', [
                'model' => $model,
                'size_bytes' => strlen($imageData),
                'mime_type' => $mimeType,
            ]);

            return [
                [
                    'url' => $dataUrl,
                    'revised_prompt' => $prompt,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace image generation error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace image generation error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        // Not directly supported by HuggingFace Inference Providers
        throw new ProviderException('Image variations not supported by HuggingFace provider', 'huggingface');
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        // Image editing with mask requires FLUX Kontext or similar
        // This is a simplified implementation using image-to-image
        // Note: maskUrl is currently ignored as we use image-to-image mode
        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            // Use configured model or default to FLUX.1-Kontext-dev
            $model = 'black-forest-labs/FLUX.1-Kontext-dev';
            $provider = 'fal-ai';

            // Security check: Prevent path traversal
            $normalizedPath = $this->uploadDir.'/'.ltrim($imageUrl, '/');
            $realPath = realpath($normalizedPath);
            $realUploadDir = realpath($this->uploadDir);

            if (false === $realPath || false === $realUploadDir || !str_starts_with($realPath, $realUploadDir)) {
                throw new \Exception("Invalid image path: {$imageUrl}");
            }

            $imageContents = @file_get_contents($realPath);
            if (false === $imageContents) {
                throw new \Exception("Failed to read image file: {$realPath}");
            }

            $imageData = base64_encode($imageContents);

            $this->logger->info('HuggingFace image edit request', [
                'model' => $model,
                'prompt' => $prompt,
            ]);

            $url = self::ROUTER_BASE."/{$provider}/models/{$model}";

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'inputs' => $imageData,
                    'parameters' => [
                        'prompt' => $prompt,
                        'guidance_scale' => 7.5,
                    ],
                ],
                'timeout' => 180,
            ]);

            $resultData = $response->getContent();

            // Save result
            $filename = 'hf_edit_'.uniqid().'.png';
            $relativePath = 'generated/'.$filename;
            $fullPath = $this->uploadDir.'/'.$relativePath;

            $dir = dirname($fullPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new \RuntimeException(sprintf('Failed to create directory "%s" for HuggingFace image edit result.', $dir));
            }

            $bytesWritten = file_put_contents($fullPath, $resultData);
            if (false === $bytesWritten) {
                throw new \RuntimeException(sprintf('Failed to write edited image to "%s".', $fullPath));
            }

            return $relativePath;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace image edit error', [
                'error' => $e->getMessage(),
            ]);

            throw new ProviderException('HuggingFace image edit error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    // ==================== VIDEO GENERATION ====================

    /**
     * Generate video from text prompt using fal.ai via HuggingFace router.
     *
     * Supported models via fal-ai:
     * - fal-ai/ltx-video (fast, recommended)
     * - fal-ai/hunyuan-video (higher quality, slower)
     *
     * @param string $prompt  Text description of the video to generate
     * @param array  $options Model options: model (required), num_frames, num_inference_steps, seed
     *
     * @return array Array with video metadata including local path
     */
    /**
     * Generate video via fal.ai's async queue API (submit → poll → result).
     *
     * fal.ai video models require queue-based processing because generation
     * typically exceeds synchronous timeout limits (~2+ min).
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', 'huggingface');
        }

        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey('huggingface', 'HUGGINGFACE_API_KEY');
        }

        try {
            $model = $options['model'];
            $falModelId = $this->buildFalModelId($model);

            $this->logger->info('HuggingFace video generation request (queue)', [
                'model' => $model,
                'fal_model_id' => $falModelId,
                'prompt_length' => strlen($prompt),
            ]);

            $requestBody = [
                'prompt' => $prompt,
                'num_inference_steps' => (int) ($options['num_inference_steps'] ?? self::DEFAULT_VIDEO_INFERENCE_STEPS),
            ];

            if (isset($options['seed'])) {
                $requestBody['seed'] = (int) $options['seed'];
            }
            if (isset($options['negative_prompt'])) {
                $requestBody['negative_prompt'] = $options['negative_prompt'];
            }
            if (isset($options['guidance_scale'])) {
                $requestBody['guidance_scale'] = (float) $options['guidance_scale'];
            }

            // 1. Submit via HuggingFace Router (proxies to fal.ai queue)
            //    The ?_subdomain=queue parameter tells the router to use fal's queue endpoint
            //    (same mechanism the official HuggingFace Python/JS SDKs use).
            $submitUrl = self::ROUTER_BASE.'/fal-ai/'.$falModelId.'?_subdomain=queue';
            $submitResponse = $this->httpClient->request('POST', $submitUrl, [
                'headers' => $this->falAuthHeaders(),
                'json' => $requestBody,
                'timeout' => 30,
            ]);

            $statusCode = $submitResponse->getStatusCode();
            if (402 === $statusCode) {
                throw new ProviderException(sprintf('HuggingFace video generation requires prepaid credits. Add credits at %s', self::BILLING_URL), 'huggingface');
            }

            $submitData = $submitResponse->toArray();
            $requestId = $submitData['request_id'] ?? null;
            $statusUrl = $submitData['status_url'] ?? null;
            $responseUrl = $submitData['response_url'] ?? null;

            if (!$requestId || !$statusUrl || !$responseUrl) {
                throw new ProviderException('fal.ai queue submit missing request_id or URLs: '.json_encode(array_keys($submitData)), 'huggingface');
            }

            $this->logger->info('HuggingFace video queued', [
                'request_id' => $requestId,
                'fal_model_id' => $falModelId,
                'status' => $submitData['status'] ?? 'unknown',
            ]);

            // 2. Poll for completion (status URLs accept HF Bearer tokens)
            $this->pollUntilComplete($statusUrl, $requestId, $falModelId);

            // 3. Fetch result from the response URL returned by fal
            $resultResponse = $this->httpClient->request('GET', $responseUrl, [
                'headers' => $this->falAuthHeaders(),
                'timeout' => 60,
            ]);

            $data = $resultResponse->toArray();

            if (!isset($data['video']['url'])) {
                throw new ProviderException('fal.ai response missing video URL: '.json_encode(array_keys($data)), 'huggingface');
            }

            $videoUrl = $data['video']['url'];

            $this->logger->info('HuggingFace video generated', [
                'model' => $model,
                'video_url' => $videoUrl,
                'seed' => $data['seed'] ?? null,
            ]);

            return [
                [
                    'url' => $videoUrl,
                    'seed' => $data['seed'] ?? null,
                    'resolution' => $options['resolution'] ?? null,
                ],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace video generation error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace video generation error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
    }

    /**
     * Poll fal.ai queue status until the request completes or fails.
     */
    private function pollUntilComplete(string $statusUrl, string $requestId, string $falModelId): void
    {
        for ($attempt = 0; $attempt < self::QUEUE_MAX_POLL_ATTEMPTS; ++$attempt) {
            sleep(self::QUEUE_POLL_INTERVAL_SECONDS);

            $statusResponse = $this->httpClient->request('GET', $statusUrl, [
                'headers' => $this->falAuthHeaders(),
                'timeout' => 15,
            ]);

            $statusData = $statusResponse->toArray();
            $status = $statusData['status'] ?? 'UNKNOWN';

            if ('COMPLETED' === $status) {
                $this->logger->info('HuggingFace video queue completed', [
                    'request_id' => $requestId,
                    'attempt' => $attempt + 1,
                ]);

                return;
            }

            if ('FAILED' === $status) {
                $error = $statusData['error'] ?? 'Unknown queue error';
                throw new ProviderException("fal.ai video generation failed: {$error}", 'huggingface');
            }

            if (0 === $attempt % 10) {
                $this->logger->debug('HuggingFace video queue polling', [
                    'request_id' => $requestId,
                    'status' => $status,
                    'attempt' => $attempt + 1,
                    'fal_model_id' => $falModelId,
                ]);
            }
        }

        throw new ProviderException(
            sprintf('fal.ai video generation timed out after %d seconds', self::QUEUE_POLL_INTERVAL_SECONDS * self::QUEUE_MAX_POLL_ATTEMPTS),
            'huggingface'
        );
    }

    /**
     * Build the fal.ai model identifier (e.g. "fal-ai/ltx-video").
     */
    private function buildFalModelId(string $model): string
    {
        if (str_contains($model, '/')) {
            return $model;
        }

        return 'fal-ai/'.$model;
    }

    /**
     * @return array<string, string>
     */
    private function falAuthHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];
    }

    // ==================== HELPERS ====================

    /**
     * Build model string with provider strategy suffix.
     *
     * @param string $model   Base model name (e.g., 'deepseek-ai/DeepSeek-R1')
     * @param array  $options May contain 'provider_strategy' (fastest, cheapest, or provider name)
     */
    private function buildModelString(string $model, array $options): string
    {
        // If model already has a suffix, use as-is
        if (str_contains($model, ':')) {
            return $model;
        }

        // Add provider strategy suffix if specified
        if (isset($options['provider_strategy'])) {
            return $model.':'.$options['provider_strategy'];
        }

        return $model;
    }
}
