<?php

declare(strict_types=1);

namespace App\AI\Provider\Google;

use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * Gemini vision for GoogleProvider: describe an image, read the text in it,
 * answer a prompt about it and compare two images, each sent inline to
 * generateContent from the upload directory.
 */
final class GoogleVision
{
    private const DEFAULT_TIMEOUT_SECONDS = 60;
    private const DEFAULT_MAX_OUTPUT_TOKENS = 8192;

    public function __construct(
        private readonly GoogleApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir,
    ) {
    }

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
        $key = $this->api->requireKey();

        try {
            $fullPath1 = $this->uploadDir.'/'.ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir.'/'.ltrim($imageUrl2, '/');

            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception('One or both images not found');
            }

            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            if (false === $imageData1 || false === $imageData2) {
                throw new \RuntimeException('Failed to read one or both images');
            }
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1) ?: 'application/octet-stream';
            $mimeType2 = mime_content_type($fullPath2) ?: 'application/octet-stream';

            $model = 'gemini-3.8-flash';
            $url = $this->api->modelUrl($model, 'generateContent');

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

            $response = $this->api->postJson($url, $payload, $key, 60);

            $data = $response->toArray();
            $this->api->checkFinishReason($data);
            $comparison = self::extractText($data);

            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google image comparison error: '.$e->getMessage(), 'google');
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        $key = $this->api->requireKey();

        try {
            $model = $options['model'] ?? 'gemini-3.8-flash';

            $fullPath = $this->uploadDir.'/'.ltrim($imagePath, '/');

            if (!file_exists($fullPath)) {
                throw new \Exception("Image file not found: {$fullPath}");
            }

            $imageData = file_get_contents($fullPath);
            if (false === $imageData) {
                throw new \RuntimeException("Failed to read image file: {$fullPath}");
            }
            $base64Image = base64_encode($imageData);
            $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';

            $this->logger->info('Google: Analyzing image', [
                'model' => $model,
                'image' => basename($imagePath),
                'prompt_length' => strlen($prompt),
            ]);

            $url = $this->api->modelUrl($model, 'generateContent');
            $gemini4 = Gemini4Models::usesThinkingLevel($model);

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
                    'maxOutputTokens' => $options['max_tokens'] ?? ($gemini4 ? Gemini4Models::MAX_OUTPUT_TOKENS : self::DEFAULT_MAX_OUTPUT_TOKENS),
                ],
            ];

            $timeout = $gemini4 ? Gemini4Models::TIMEOUT_SECONDS : self::DEFAULT_TIMEOUT_SECONDS;
            $response = $this->api->postJson($url, $payload, $key, $timeout);

            $data = $response->toArray();
            $this->api->checkFinishReason($data);

            return self::extractText($data);
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google vision error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Concatenate non-thought text parts from the first candidate so Gemini
     * thinking parts never replace or hide the visible vision answer.
     *
     * @param array<string, mixed> $data
     */
    private static function extractText(array $data): string
    {
        $text = '';
        $parts = $data['candidates'][0]['content']['parts'] ?? [];
        if (!\is_array($parts)) {
            return '';
        }

        foreach ($parts as $part) {
            if (\is_array($part) && isset($part['text']) && \is_string($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }

        return $text;
    }
}
