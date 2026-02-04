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
    private const DEFAULT_VIDEO_FRAMES = 65;
    private const DEFAULT_VIDEO_INFERENCE_STEPS = 25;
    private const VIDEO_TIMEOUT = 600;

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

            $this->logger->info('HuggingFace video generation request', [
                'model' => $model,
                'prompt_length' => strlen($prompt),
            ]);

            // fal.ai models use format: fal-ai/fal-ai/{model-name}
            // The model ID in options should be like "ltx-video" or "hunyuan-video"
            $url = self::ROUTER_BASE."/fal-ai/fal-ai/{$model}";

            // fal.ai uses 'prompt' at root level, not 'inputs'
            $requestBody = [
                'prompt' => $prompt,
            ];

            // Add optional parameters at root level (fal.ai format)
            if (isset($options['num_frames'])) {
                $requestBody['num_frames'] = (int) $options['num_frames'];
            } else {
                $requestBody['num_frames'] = self::DEFAULT_VIDEO_FRAMES; // Default ~2.5 seconds
            }

            if (isset($options['num_inference_steps'])) {
                $requestBody['num_inference_steps'] = (int) $options['num_inference_steps'];
            } else {
                $requestBody['num_inference_steps'] = self::DEFAULT_VIDEO_INFERENCE_STEPS; // Balance quality/speed
            }

            if (isset($options['seed'])) {
                $requestBody['seed'] = (int) $options['seed'];
            }

            if (isset($options['negative_prompt'])) {
                $requestBody['negative_prompt'] = $options['negative_prompt'];
            }

            $response = $this->httpClient->request('POST', $url, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
                'timeout' => self::VIDEO_TIMEOUT, // Video generation can take several minutes
            ]);

            $statusCode = $response->getStatusCode();

            // Check for payment required error
            if (402 === $statusCode) {
                throw new ProviderException(sprintf('HuggingFace video generation requires prepaid credits. Add credits at %s', self::BILLING_URL), 'huggingface');
            }

            // fal.ai returns JSON with video URL
            $data = $response->toArray();

            if (!isset($data['video']['url'])) {
                throw new ProviderException('Invalid response from fal.ai: missing video URL', 'huggingface');
            }

            // Download video from fal.ai CDN using HttpClient
            $videoUrl = $data['video']['url'];

            try {
                $videoResponse = $this->httpClient->request('GET', $videoUrl);

                if (200 !== $videoResponse->getStatusCode()) {
                    throw new ProviderException(sprintf('Failed to download video from fal.ai CDN (HTTP %d)', $videoResponse->getStatusCode()), 'huggingface');
                }

                $videoData = $videoResponse->getContent();
            } catch (\Throwable $e) {
                throw new ProviderException('Failed to download video from fal.ai CDN', 'huggingface', null, 0, $e);
            }

            // Save video to uploads
            $filename = 'hf_video_'.uniqid().'.mp4';
            $relativePath = 'generated/'.$filename;
            $fullPath = $this->uploadDir.'/'.$relativePath;

            // Ensure directory exists
            $dir = dirname($fullPath);
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw new ProviderException('Failed to create directory for HuggingFace video output: '.$dir, 'huggingface');
            }

            $bytesWritten = file_put_contents($fullPath, $videoData);
            if (false === $bytesWritten) {
                throw new ProviderException('Failed to write generated video to '.$fullPath, 'huggingface');
            }

            $this->logger->info('HuggingFace video generated', [
                'model' => $model,
                'path' => $relativePath,
                'size_bytes' => strlen($videoData),
                'seed' => $data['seed'] ?? null,
            ]);

            // Calculate approximate duration (65 frames @ 24fps ≈ 2.7s)
            $numFrames = $options['num_frames'] ?? 65;
            $duration = round($numFrames / 24, 1);

            return [
                [
                    'url' => $relativePath,
                    'duration' => $duration,
                    'resolution' => '720p',
                    'seed' => $data['seed'] ?? null,
                ],
            ];
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace video generation error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);

            throw new ProviderException('HuggingFace video generation error: '.$e->getMessage(), 'huggingface', null, 0, $e);
        }
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
