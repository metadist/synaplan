<?php

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

    /**
     * Argon can think for minutes before the first token, and thinking tokens
     * count against maxOutputTokens.
     */
    private const ARGON_TIMEOUT_SECONDS = 900;
    private const ARGON_MAX_OUTPUT_TOKENS = 65536;

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
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $model = 'gemini-2.5-pro';
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
        $key = $this->api->requireKey();

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

            $url = $this->api->modelUrl($model, 'generateContent');
            $isArgon = 1 === preg_match('/(?:^|\/)gemini-4-argon(?:$|[:\/])/', strtolower($model));

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
                    'maxOutputTokens' => $options['max_tokens'] ?? ($isArgon ? self::ARGON_MAX_OUTPUT_TOKENS : self::DEFAULT_MAX_OUTPUT_TOKENS),
                ],
            ];

            $timeout = $isArgon ? self::ARGON_TIMEOUT_SECONDS : self::DEFAULT_TIMEOUT_SECONDS;
            $response = $this->api->postJson($url, $payload, $key, $timeout);

            $data = $response->toArray();

            return $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        } catch (\Exception $e) {
            throw new ProviderException('Google vision error: '.$e->getMessage(), 'google');
        }
    }
}
