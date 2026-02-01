<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ImageGenerationProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * TheHive AI Provider - Image Generation.
 *
 * Supports SDXL and Flux models for high-quality image generation.
 *
 * @see https://docs.thehive.ai/reference/image-generation-models-reference
 */
final class TheHiveProvider implements ImageGenerationProviderInterface
{
    private const BASE_URL = 'https://api.thehive.ai/api/v3';

    /**
     * Model ID mapping to V3 API endpoint paths (vendor/model-name).
     */
    private const MODEL_ENDPOINTS = [
        'flux-schnell' => 'black-forest-labs/flux-schnell',
        'flux-schnell-enhanced' => 'hive/flux-schnell-enhanced',
        'sdxl' => 'stabilityai/sdxl',
        'sdxl-enhanced' => 'hive/sdxl-enhanced',
        'emoji' => 'hive/flux-schnell-emoji',
    ];

    /**
     * Default dimensions per model (width x height).
     */
    private const DEFAULT_DIMENSIONS = [
        'flux-schnell' => ['width' => 1024, 'height' => 1024],
        'flux-schnell-enhanced' => ['width' => 1024, 'height' => 1024],
        'sdxl' => ['width' => 1024, 'height' => 1024],
        'sdxl-enhanced' => ['width' => 1024, 'height' => 1024],
        'emoji' => ['width' => 512, 'height' => 512],
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
    ) {
    }

    public function getName(): string
    {
        return 'thehive';
    }

    public function getDisplayName(): string
    {
        return 'TheHive';
    }

    public function getDescription(): string
    {
        return 'TheHive AI - SDXL and Flux image generation models';
    }

    public function getCapabilities(): array
    {
        return ['image_generation'];
    }

    public function getDefaultModels(): array
    {
        return [
            'image_generation' => 'flux-schnell',
        ];
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
            'latency_ms' => 50,
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
            'THEHIVE_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key from https://thehive.ai/',
            ],
        ];
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        if (!$this->apiKey) {
            throw ProviderException::missingApiKey('thehive', 'THEHIVE_API_KEY');
        }

        $model = $options['model'] ?? 'flux-schnell';
        $modelEndpoint = self::MODEL_ENDPOINTS[$model] ?? self::MODEL_ENDPOINTS['flux-schnell'];

        // Parse size option (e.g., "1024x1024")
        $dimensions = $this->parseDimensions(
            $options['size'] ?? null,
            $model,
            isset($options['width']) ? (int) $options['width'] : null,
            isset($options['height']) ? (int) $options['height'] : null,
        );

        $this->logger->info('TheHive: Generating image', [
            'model' => $model,
            'endpoint' => $modelEndpoint,
            'prompt_length' => strlen($prompt),
            'dimensions' => $dimensions,
        ]);

        try {
            // V3 API uses input object with image_size
            $requestBody = [
                'input' => [
                    'prompt' => $prompt,
                    'num_images' => $options['n'] ?? 1,
                    'image_size' => [
                        'width' => $dimensions['width'],
                        'height' => $dimensions['height'],
                    ],
                ],
            ];

            // Only SDXL models support negative_prompt
            $supportsNegativePrompt = str_starts_with($model, 'sdxl');

            if ($supportsNegativePrompt) {
                // Add negative prompt if provided
                if (!empty($options['negative_prompt'])) {
                    $requestBody['input']['negative_prompt'] = $options['negative_prompt'];
                }

                // Add style as negative prompt if not already set
                if (!empty($options['style']) && empty($options['negative_prompt'])) {
                    // Map style to negative prompts
                    $styleNegatives = [
                        'natural' => 'artificial, unrealistic, cartoon, anime',
                        'vivid' => 'dull, muted, washed out, faded',
                        'artistic' => 'photorealistic, photo, realistic',
                    ];
                    if (isset($styleNegatives[$options['style']])) {
                        $requestBody['input']['negative_prompt'] = $styleNegatives[$options['style']];
                    }
                }
            }

            // V3 API: Model name is in the URL path
            $endpoint = self::BASE_URL.'/'.$modelEndpoint;

            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => [
                    'Authorization' => 'Bearer '.$this->apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestBody,
                'timeout' => 120,
            ]);

            $statusCode = $response->getStatusCode();
            $responseData = $response->toArray(false);

            $this->logger->debug('TheHive: Raw response', [
                'status_code' => $statusCode,
                'response' => $responseData,
            ]);

            // Handle error responses
            if ($statusCode >= 400) {
                $this->handleErrorResponse($statusCode, $responseData);
            }

            // Check for task-level errors
            if (isset($responseData['code']) && $responseData['code'] >= 400) {
                $this->handleErrorResponse($responseData['code'], $responseData);
            }

            // Parse successful response
            return $this->parseImageResponse($responseData);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('TheHive: Image generation failed', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);
            throw new ProviderException('TheHive image generation error: '.$e->getMessage(), 'thehive');
        }
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('TheHive does not support image variations', 'thehive');
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        throw new ProviderException('TheHive does not support image editing', 'thehive');
    }

    // ==================== HELPER METHODS ====================

    /**
     * Parse size string (e.g., "1024x1024") into width/height array.
     */
    private function parseDimensions(?string $size, string $model, ?int $width, ?int $height): array
    {
        if ($width && $height) {
            return ['width' => $width, 'height' => $height];
        }

        // Use default dimensions for the model if no size specified
        if (!$size) {
            return self::DEFAULT_DIMENSIONS[$model] ?? ['width' => 1024, 'height' => 1024];
        }

        // Parse "WIDTHxHEIGHT" format
        if (preg_match('/^(\d+)x(\d+)$/', $size, $matches)) {
            return [
                'width' => (int) $matches[1],
                'height' => (int) $matches[2],
            ];
        }

        // Fallback to defaults
        return self::DEFAULT_DIMENSIONS[$model] ?? ['width' => 1024, 'height' => 1024];
    }

    /**
     * Handle error responses from TheHive API.
     */
    private function handleErrorResponse(int $statusCode, array $responseData): void
    {
        $message = $responseData['message'] ?? $responseData['error'] ?? 'Unknown error';

        // Rate limiting
        if (429 === $statusCode) {
            throw new ProviderException('TheHive rate limit exceeded. Please try again later.', 'thehive', ['status_code' => $statusCode]);
        }

        // Content moderation (code 451)
        if (451 === $statusCode || (isset($responseData['return_code']) && 451 === $responseData['return_code'])) {
            throw new ProviderException('Content policy violation: The prompt was rejected by TheHive safety system', 'thehive');
        }

        if (isset($responseData['output'][0]['content_moderation']['safe'])
            && false === $responseData['output'][0]['content_moderation']['safe']) {
            throw new ProviderException('Content policy violation: The prompt was rejected by TheHive safety system', 'thehive');
        }

        // Authentication errors - show actual message
        if (401 === $statusCode || 403 === $statusCode) {
            $this->logger->error('TheHive: Authentication failed', [
                'status_code' => $statusCode,
                'message' => $message,
            ]);
            throw new ProviderException("TheHive authentication error: {$message}", 'thehive', ['status_code' => $statusCode]);
        }

        throw new ProviderException("TheHive API error ({$statusCode}): {$message}", 'thehive', ['status_code' => $statusCode, 'response' => $responseData]);
    }

    /**
     * Parse successful image generation response.
     */
    private function parseImageResponse(array $responseData): array
    {
        $images = [];

        $output = $responseData['output'] ?? [];

        foreach ($output as $item) {
            $url = $item['url'] ?? null;

            if (!$url) {
                $this->logger->warning('TheHive: Image response missing URL', ['item' => $item]);
                continue;
            }

            $images[] = [
                'url' => $url,
                'b64_json' => null, // TheHive returns URLs, not base64
                'revised_prompt' => null, // TheHive doesn't return revised prompts
                'content_moderation' => $item['content_moderation'] ?? null,
            ];
        }

        if (empty($images)) {
            $this->logger->error('TheHive: No images in response', ['response' => $responseData]);
            throw new ProviderException('TheHive returned no images. Response format may have changed.', 'thehive');
        }

        $this->logger->info('TheHive: Image generation successful', [
            'count' => count($images),
        ]);

        return $images;
    }
}
