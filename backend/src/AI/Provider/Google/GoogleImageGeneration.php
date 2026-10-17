<?php

namespace App\AI\Provider\Google;

use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * Gemini image generation for GoogleProvider: Gemini native image models
 * (text to image and image to image), Imagen on the Gemini API or on Vertex
 * AI, edits, and the answers that carry no image (a refusal in prose, a
 * filtered result) as ProviderExceptions the caller can show.
 */
final class GoogleImageGeneration
{
    public function __construct(
        private readonly GoogleApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir,
    ) {
    }

    public function generateImage(string $prompt, array $options = []): array
    {
        // Nano Banana 2, the successor ModelCatalog::RETIREMENTS[115] records for
        // Imagen 4.0: Google shut the Imagen 4 endpoints down on 2026-08-17, so
        // the previous default 404'd for every caller that passed no model.
        $model = $options['model'] ?? 'gemini-3.1-flash-image';
        $inputImages = $options['images'] ?? [];

        $this->api->requireKey();

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
        $key = $this->api->requireKey();

        try {
            $url = $this->api->modelUrl($model, 'generateContent');

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

            $response = $this->api->postJson($url, $payload, $key, 120);

            $data = $response->toArray();

            $this->api->checkFinishReason($data);

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

            if ([] === $images) {
                throw $this->geminiNoImage($data, $model);
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
     * @param string   $model      Gemini image model (e.g. gemini-3.1-flash-image)
     * @param string   $prompt     Text instruction
     * @param string[] $imagePaths Absolute paths to input images
     *
     * @see https://ai.google.dev/gemini-api/docs/image-generation
     */
    private function generateImageFromImagesWithGemini(string $model, string $prompt, array $imagePaths, array $options): array
    {
        $key = $this->api->requireKey();

        try {
            $url = $this->api->modelUrl($model, 'generateContent');

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

            $response = $this->api->postJson($url, $payload, $key, 120);

            $data = $response->toArray();

            $this->api->checkFinishReason($data);

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

            if ([] === $images) {
                throw $this->geminiNoImage($data, $model);
            }

            return $images;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google Gemini pic2pic error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * A finish the content check lets through (STOP, MAX_TOKENS, …) can still
     * carry no image, e.g. when the model answers a question about the photo
     * in prose. The exception keeps that reply so callers can show it.
     *
     * @param array<string, mixed> $data generateContent response
     */
    private function geminiNoImage(array $data, string $model): ProviderException
    {
        $candidate = is_array($data['candidates'][0] ?? null) ? $data['candidates'][0] : [];
        $finishReason = is_string($candidate['finishReason'] ?? null) ? $candidate['finishReason'] : null;

        $textResponse = null;
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (true === ($part['thought'] ?? false)) {
                continue;
            }
            if (is_string($part['text'] ?? null) && '' !== trim($part['text'])) {
                $textResponse = $part['text'];
                break;
            }
        }

        $exception = ProviderException::noImage('google', $model, $textResponse, $finishReason);
        $this->logger->warning('Google Gemini: image model returned no image', $exception->logContext());

        return $exception;
    }

    /**
     * Generate image using Imagen via Gemini API (API key) or Vertex AI (project ID + OAuth).
     *
     * The catalog carries no Imagen row any more — Google shut the Imagen 4 ids
     * down on 2026-08-17 (see ModelCatalog::RETIREMENTS[115]) — so this path now
     * only serves an Imagen model an operator added themselves.
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
            if ($this->api->usesVertex()) {
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
        $key = $this->api->requireKey();
        $url = $this->api->modelUrl($model, 'predict');

        $this->logger->info('Google Imagen: Using Gemini API', [
            'model' => $model,
            'url' => $url,
        ]);

        $response = $this->api->postJson($url, $payload, $key, 120);

        return $this->parseImagenResponse($response->toArray(), $model);
    }

    /**
     * Imagen via Vertex AI (GCP project + OAuth2 access token from GOOGLE_VERTEX_ACCESS_TOKEN).
     */
    private function generateImageWithImagenVertex(string $model, array $payload): array
    {
        if (null === $this->api->vertexAccessToken()) {
            throw new ProviderException('Vertex Imagen requires GOOGLE_VERTEX_ACCESS_TOKEN (OAuth bearer), not the Gemini API key', 'google');
        }

        $url = $this->api->vertexModelUrl($model, 'predict');

        $this->logger->info('Google Imagen: Using Vertex AI', [
            'model' => $model,
            'project' => $this->api->projectId(),
        ]);

        $response = $this->api->postVertexJson($url, $payload, 120);

        return $this->parseImagenResponse($response->toArray(), $model);
    }

    private function parseImagenResponse(array $data, string $model): array
    {
        $images = [];
        $filteredReason = null;
        foreach ($data['predictions'] ?? [] as $prediction) {
            if (isset($prediction['bytesBase64Encoded'])) {
                $mimeType = $prediction['mimeType'] ?? 'image/png';
                $images[] = [
                    'url' => 'data:'.$mimeType.';base64,'.$prediction['bytesBase64Encoded'],
                    'revised_prompt' => null,
                ];
            } elseif (null === $filteredReason && is_string($prediction['raiFilteredReason'] ?? null)) {
                $filteredReason = $prediction['raiFilteredReason'];
            }
        }

        if ([] === $images) {
            $exception = null !== $filteredReason && '' !== trim($filteredReason)
                ? ProviderException::imageFiltered('google', $model, $filteredReason)
                : ProviderException::noImage('google', $model, null, null);
            $this->logger->warning('Google Imagen: no image in response', $exception->logContext());

            throw $exception;
        }

        return $images;
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Image variations not supported by Google Imagen', 'google');
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        // Google Gemini 2.5 Flash Image supports editing
        $model = 'gemini-2.5-flash-image-preview';

        $key = $this->api->requireKey();

        try {
            // Read the image
            $fullPath = $this->uploadDir.'/'.ltrim($imageUrl, '/');
            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            $imageData = file_get_contents($fullPath);
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath);

            $url = $this->api->modelUrl($model, 'generateContent');

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

            $response = $this->api->postJson($url, $payload, $key, 60);

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
}
