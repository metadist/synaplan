<?php

namespace App\AI\Provider\OpenAI;

use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * OpenAI vision for OpenAIProvider: describe an image, read its text,
 * answer a prompt about it and compare two images. Chat models answer on
 * chat completions; reasoning models, and a model that turns out to be no
 * chat model, on the Responses API, where an answer without text is an
 * error instead of an empty result.
 */
final class OpenAIVision
{
    public function __construct(
        private readonly OpenAIApiClient $api,
        private readonly OpenAIResponses $responses,
        private readonly LoggerInterface $logger,
        private readonly string $uploadDir,
        private readonly bool $storeResponses,
    ) {
    }

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        // Use analyzeImage internally
        $defaultPrompt = 'Describe what you see in this image in detail.';

        return $this->analyzeImage($imageUrl, $prompt ?: $defaultPrompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->analyzeImage($imageUrl, 'Extract all text from this image. Return only the text, nothing else.');
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        try {
            // Build full paths
            $fullPath1 = $this->uploadDir.'/'.ltrim($imageUrl1, '/');
            $fullPath2 = $this->uploadDir.'/'.ltrim($imageUrl2, '/');

            if (!file_exists($fullPath1) || !file_exists($fullPath2)) {
                throw new \Exception('One or both images not found');
            }

            // Read images and convert to base64
            $imageData1 = file_get_contents($fullPath1);
            $imageData2 = file_get_contents($fullPath2);
            $base64Image1 = base64_encode($imageData1);
            $base64Image2 = base64_encode($imageData2);
            $mimeType1 = mime_content_type($fullPath1);
            $mimeType2 = mime_content_type($fullPath2);

            $response = $this->api->sdk()->chat()->create([
                'model' => 'gpt-4o',
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Compare these two images and describe the similarities and differences.',
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

            $comparison = $response['choices'][0]['message']['content'] ?? '';

            return [
                'comparison' => $comparison,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI image comparison error: '.$e->getMessage(), 'openai');
        }
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        if (null === $this->api->sdk()) {
            throw ProviderException::missingApiKey('openai', 'OPENAI_API_KEY');
        }

        $model = $options['model'] ?? 'gpt-4o';

        // Build full path
        $fullPath = $this->uploadDir.'/'.ltrim($imagePath, '/');

        // Check if file exists. Keep the absolute path in logs only; the
        // message may be surfaced to API clients (MediaController returns
        // ProviderException::getMessage()), so it must not leak server paths.
        if (!file_exists($fullPath)) {
            $this->logger->error('OpenAI: image file not found', ['path' => $fullPath]);
            throw new ProviderException('OpenAI vision error: Image file not found: '.basename($imagePath), 'openai');
        }

        // Read image and convert to a data URL. Both calls can fail (races,
        // permissions, unreadable content); guard them so we never build a
        // malformed data URL from a false return value.
        $imageData = file_get_contents($fullPath);
        $mimeType = mime_content_type($fullPath);
        if (false === $imageData || false === $mimeType) {
            $this->logger->error('OpenAI: failed to read image or detect MIME type', ['path' => $fullPath]);
            throw new ProviderException('OpenAI vision error: Unable to read image file: '.basename($imagePath), 'openai');
        }

        $base64Image = base64_encode($imageData);
        $dataUrl = "data:{$mimeType};base64,{$base64Image}";

        $this->logger->info('OpenAI: Analyzing image', [
            'model' => $model,
            'image' => basename($imagePath),
            'prompt_length' => strlen($prompt),
        ]);

        // Reasoning-tier models (o-series, gpt-5+) are served by the Responses
        // API. The "pro" tiers in particular (gpt-5-pro, gpt-5.5-pro, o*-pro)
        // are ONLY available there and reject v1/chat/completions with
        // "This is not a chat model and thus not supported in the
        // v1/chat/completions endpoint" — which silently produced empty OCR.
        // Route them through the Responses API exactly like chat()/chatStream()
        // already do; older multimodal chat models (gpt-4o, gpt-4.1, …) keep
        // the Chat Completions vision path.
        if ($this->responses->usesCompletionTokens($model)) {
            return $this->analyzeImageViaResponses($model, $prompt, $dataUrl, $options);
        }

        try {
            $payload = [
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
                                'url' => $dataUrl,
                            ],
                        ],
                    ],
                ]],
                'max_tokens' => $options['max_tokens'] ?? 1000,
            ];

            $response = $this->api->sdk()->chat()->create($payload);

            return $response['choices'][0]['message']['content'] ?? '';
        } catch (\Exception $e) {
            // Safety net: if a model we treated as chat-compatible turns out to
            // be Responses-only, retry there instead of failing the OCR.
            if ($this->isNotAChatModelError($e)) {
                $this->logger->warning('OpenAI: model rejected by v1/chat/completions, retrying image analysis via Responses API', [
                    'model' => $model,
                    'error' => $e->getMessage(),
                ]);

                return $this->analyzeImageViaResponses($model, $prompt, $dataUrl, $options);
            }

            throw new ProviderException('OpenAI vision error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Vision via the Responses API — the path for reasoning models (o-series,
     * gpt-5+) and the Responses-only "pro" tiers that v1/chat/completions
     * rejects.
     *
     * Mirrors the request shaping in {@see OpenAIChat::chat()} (input turns,
     * per-model reasoning config, the invalid-reasoning-tier fallback in
     * {@see OpenAIResponses::create()}) but with a single user turn that
     * carries the prompt plus the image as an `input_image` part.
     */
    private function analyzeImageViaResponses(string $model, string $prompt, string $dataUrl, array $options): string
    {
        try {
            // Default OCR/vision to the model's lowest reasoning tier so the
            // output-token budget isn't consumed by chain-of-thought (and
            // latency stays low). Callers may override via reasoning_effort.
            $options['reasoning_effort'] ??= 'lowest';

            $requestOptions = [
                'model' => $model,
                'input' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'input_text', 'text' => $prompt],
                        ['type' => 'input_image', 'image_url' => $dataUrl],
                    ],
                ]],
                'store' => $this->storeResponses,
                // Reasoning tokens count against this budget, so give OCR of a
                // full page enough headroom regardless of the caller's hint.
                'max_output_tokens' => max((int) ($options['max_tokens'] ?? 0), OpenAIResponses::DEFAULT_MAX_TOKENS),
            ];

            $reasoningConfig = $this->responses->resolveReasoningConfig($model, true, $options);
            if (null !== $reasoningConfig) {
                $requestOptions['reasoning'] = $reasoningConfig;
            }

            $response = $this->responses->create($requestOptions);
            $text = $response->outputText ?? '';

            if ('' === trim($text)) {
                // A reasoning model can spend the whole output-token budget on
                // chain-of-thought and return no visible text (status
                // "incomplete", reason "max_output_tokens"). The "pro" tiers
                // force at least `medium` effort and can't be dialed down, so
                // they routinely truncate or time out on synchronous OCR.
                // Surface this as a failure so the caller falls back to another
                // vision provider instead of silently accepting an empty result.
                $arr = $response->toArray();
                $status = (string) ($arr['status'] ?? 'unknown');
                $reason = $arr['incomplete_details']['reason'] ?? null;

                throw new ProviderException(sprintf('OpenAI vision returned no text (model=%s, status=%s%s). Reasoning-heavy models such as the "pro" tiers are a poor fit for synchronous OCR; select a non-pro vision model (e.g. gpt-5.5).', $model, $status, null !== $reason ? ', reason='.$reason : ''), 'openai');
            }

            return $text;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('OpenAI vision error: '.$e->getMessage(), 'openai');
        }
    }

    /**
     * Detect the HTTP 400 OpenAI returns when a Responses-only model (e.g. the
     * "pro" reasoning tiers) is called on v1/chat/completions:
     *   "This is not a chat model and thus not supported in the
     *    v1/chat/completions endpoint. Did you mean to use v1/completions?".
     */
    private function isNotAChatModelError(\Throwable $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'not a chat model')
            || str_contains($message, 'v1/chat/completions');
    }
}
