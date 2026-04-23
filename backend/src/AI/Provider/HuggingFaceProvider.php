<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\EmbeddingProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * HuggingFace Inference Providers - Unified API for 200k+ models.
 *
 * Uses a single HF token to access multiple backend providers (Fal AI, SambaNova, Groq, etc.)
 * through the router.huggingface.co proxy. Billing is post-paid via the HuggingFace
 * account (no per-provider wallet required).
 *
 * Supports:
 * - Chat (OpenAI-compatible chat-completions endpoint)
 * - Vision (multimodal chat with `image_url` content blocks)
 * - Embeddings (feature extraction)
 * - Image Generation (text-to-image)
 * - Video Generation (text-to-video, async queue via fal.ai)
 *
 * @see https://huggingface.co/docs/inference-providers/index
 */
class HuggingFaceProvider implements ChatProviderInterface, EmbeddingProviderInterface, ImageGenerationProviderInterface, VideoGenerationProviderInterface, VisionProviderInterface
{
    private const PROVIDER_NAME = 'huggingface';
    private const DISPLAY_NAME = 'HuggingFace';

    private const ROUTER_BASE = 'https://router.huggingface.co';
    private const CHAT_ENDPOINT = self::ROUTER_BASE.'/v1/chat/completions';
    private const BILLING_URL = 'https://huggingface.co/settings/billing';
    private const TOKENS_URL = 'https://huggingface.co/settings/tokens';

    private const DEFAULT_SUB_PROVIDER = 'hf-inference';
    private const FAL_PROVIDER = 'fal-ai';

    /**
     * Default vision model used when {@see extractTextFromImage()} or
     * {@see compareImages()} are called without an explicit model. Both
     * methods are part of {@see VisionProviderInterface} and have no
     * options parameter, so a reasonable HF-routed default is required.
     */
    private const DEFAULT_VISION_MODEL = 'moonshotai/Kimi-K2.6';
    private const DEFAULT_VISION_MAX_TOKENS = 1000;

    private const TIMEOUT_CHAT_SECONDS = 120;
    private const TIMEOUT_EMBED_SECONDS = 60;
    private const TIMEOUT_EMBED_BATCH_SECONDS = 120;
    private const TIMEOUT_IMAGE_SECONDS = 180;
    private const TIMEOUT_VIDEO_SUBMIT_SECONDS = 30;
    private const TIMEOUT_VIDEO_RESULT_SECONDS = 60;
    private const TIMEOUT_VIDEO_POLL_SECONDS = 15;

    private const DEFAULT_VIDEO_INFERENCE_STEPS = 30;
    private const QUEUE_POLL_INTERVAL_SECONDS = 3;
    private const QUEUE_MAX_POLL_ATTEMPTS = 180;
    private const QUEUE_POLL_LOG_EVERY = 10;

    private const DEFAULT_IMAGE_EDIT_MODEL = 'black-forest-labs/FLUX.1-Kontext-dev';
    private const DEFAULT_IMAGE_EDIT_GUIDANCE_SCALE = 7.5;

    /**
     * Optional chat parameters that are forwarded as-is to the OpenAI-compatible
     * /chat/completions endpoint when present in $options.
     *
     * Keep in sync with https://huggingface.co/docs/inference-providers/tasks/chat-completion
     */
    private const FORWARDABLE_CHAT_OPTIONS = [
        'max_tokens',
        'temperature',
        'top_p',
        'stop',
        'seed',
        'presence_penalty',
        'frequency_penalty',
        'response_format',
        'tools',
        'tool_choice',
    ];

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    // ==================== METADATA ====================

    public function getName(): string
    {
        return self::PROVIDER_NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getDescription(): string
    {
        return 'Unified API for 200k+ models via HuggingFace Inference Providers (chat, vision, embeddings, image and video generation)';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'embedding', 'image_generation', 'video_generation'];
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

        // HF Router has no dedicated health endpoint; we cannot truthfully report
        // latency / error-rate without sampling real traffic. Return `healthy`
        // based solely on credential presence and let upstream monitoring add
        // observability if needed.
        return ['healthy' => true];
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
                'hint' => 'Get your API token from '.self::TOKENS_URL,
            ],
        ];
    }

    // ==================== CHAT (OpenAI-compatible) ====================

    public function chat(array $messages, array $options = []): array
    {
        $this->assertChatPreconditions($messages, $options);

        try {
            $model = $this->buildModelString($options['model'], $options);
            $body = $this->buildChatRequestBody($model, $messages, $options, false);

            $this->logger->info('HuggingFace chat request', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $data = $this->postChatCompletion($body);

            return [
                'content' => $data['choices'][0]['message']['content'] ?? '',
                'usage' => $this->parseUsage($data['usage'] ?? []),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logChatError('chat', $e, $options['model']);
            throw new ProviderException('HuggingFace chat error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $this->assertChatPreconditions($messages, $options);

        try {
            $model = $this->buildModelString($options['model'], $options);
            $body = $this->buildChatRequestBody($model, $messages, $options, true);

            $this->logger->info('HuggingFace streaming chat START', [
                'model' => $model,
                'message_count' => count($messages),
            ]);

            $response = $this->httpClient->request('POST', self::CHAT_ENDPOINT, [
                'headers' => $this->buildAuthHeaders(true),
                'json' => $body,
                'timeout' => self::TIMEOUT_CHAT_SECONDS,
            ]);

            // Symfony's HttpClient is lazy: request() returns before headers are received.
            // We must assert success BEFORE consuming the SSE stream, otherwise a non-2xx
            // (e.g. 402 Payment Required, 401 Unauthorized, 5xx) yields zero `data:` lines
            // and the method would emit a phantom `finish` event with empty usage,
            // masking the real error from the caller.
            $this->assertChatResponseHttpOk($response);

            [$usage, $finishReason, $chunkCount] = $this->consumeChatStream($response, $callback);

            $this->logger->info('HuggingFace streaming COMPLETE', [
                'model' => $model,
                'chunks' => $chunkCount,
                'finish_reason' => $finishReason,
            ]);

            // Honor ChatProviderInterface streaming contract (see interface docblock).
            $callback(['type' => 'finish', 'finish_reason' => $finishReason ?? 'stop']);

            return ['usage' => $usage];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logChatError('streaming', $e, $options['model']);
            throw new ProviderException('HuggingFace streaming error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== VISION (multimodal chat) ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $options['model'] ??= self::DEFAULT_VISION_MODEL;
        $this->assertApiKey();

        try {
            $model = $this->buildModelString($options['model'], $options);
            $imageDataUrl = $this->loadImageAsDataUrl($imageUrl);
            $resolvedPrompt = '' !== $prompt ? $prompt : 'Please describe this image in detail.';

            $this->logger->info('HuggingFace vision: explainImage', [
                'model' => $model,
                'image' => basename($imageUrl),
                'prompt_length' => strlen($resolvedPrompt),
            ]);

            $messages = [$this->buildVisionMessage($resolvedPrompt, [$imageDataUrl])];
            $body = $this->buildChatRequestBody($model, $messages, [
                'max_tokens' => $options['max_tokens'] ?? self::DEFAULT_VISION_MAX_TOKENS,
            ], false);

            $data = $this->postChatCompletion($body);

            return $data['choices'][0]['message']['content'] ?? '';
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace vision error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);
            throw new ProviderException('HuggingFace vision error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->explainImage(
            $imageUrl,
            'Extract all text from this image. Provide only the extracted text without any commentary.',
            ['model' => self::DEFAULT_VISION_MODEL],
        );
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        $this->assertApiKey();

        try {
            $imageDataUrl1 = $this->loadImageAsDataUrl($imageUrl1);
            $imageDataUrl2 = $this->loadImageAsDataUrl($imageUrl2);

            $this->logger->info('HuggingFace vision: compareImages', [
                'model' => self::DEFAULT_VISION_MODEL,
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ]);

            $messages = [$this->buildVisionMessage(
                'Compare these two images and describe the differences and similarities.',
                [$imageDataUrl1, $imageDataUrl2],
            )];

            $body = $this->buildChatRequestBody(
                self::DEFAULT_VISION_MODEL,
                $messages,
                ['max_tokens' => self::DEFAULT_VISION_MAX_TOKENS],
                false,
            );

            $data = $this->postChatCompletion($body);

            return [
                'comparison' => $data['choices'][0]['message']['content'] ?? '',
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace image comparison error', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('HuggingFace image comparison error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== EMBEDDINGS ====================

    public function embed(string $text, array $options = []): array
    {
        $this->assertModel($options);
        $this->assertApiKey();

        try {
            $model = $options['model'];
            $subProvider = $this->resolveSubProvider($options);

            $this->logger->info('HuggingFace embedding request', [
                'model' => $model,
                'sub_provider' => $subProvider,
                'text_length' => strlen($text),
            ]);

            $data = $this->postEmbedding(
                $this->buildSubProviderModelUrl($subProvider, $model),
                ['inputs' => $text, 'normalize' => true],
                self::TIMEOUT_EMBED_SECONDS,
            );

            // HF returns either a flat embedding [...] or a nested [[...]] depending on model
            $embedding = (isset($data[0]) && is_array($data[0])) ? $data[0] : $data;

            return [
                'embedding' => $embedding,
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace embedding error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);
            throw new ProviderException('HuggingFace embedding error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function embedBatch(array $texts, array $options = []): array
    {
        $this->assertModel($options);
        $this->assertApiKey();

        try {
            $model = $options['model'];
            $subProvider = $this->resolveSubProvider($options);

            $this->logger->info('HuggingFace batch embedding request', [
                'model' => $model,
                'sub_provider' => $subProvider,
                'batch_size' => count($texts),
            ]);

            $embeddings = $this->postEmbedding(
                $this->buildSubProviderModelUrl($subProvider, $model),
                ['inputs' => $texts, 'normalize' => true],
                self::TIMEOUT_EMBED_BATCH_SECONDS,
            );

            return [
                'embeddings' => $embeddings,
                'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace batch embedding error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);
            throw new ProviderException('HuggingFace batch embedding error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function getDimensions(string $model): int
    {
        // Common HuggingFace embedding model dimensions. Models not listed here
        // fall back to 768 (BERT base size). Add new entries as needed.
        $dimensions = [
            'intfloat/multilingual-e5-large' => 1024,
            'intfloat/multilingual-e5-base' => 768,
            'intfloat/multilingual-e5-small' => 384,
            'thenlper/gte-large' => 1024,
            'thenlper/gte-base' => 768,
            'thenlper/gte-small' => 384,
            'BAAI/bge-m3' => 1024,
            'BAAI/bge-large-en-v1.5' => 1024,
            'BAAI/bge-base-en-v1.5' => 768,
            'BAAI/bge-small-en-v1.5' => 384,
            'mixedbread-ai/mxbai-embed-large-v1' => 1024,
            'sentence-transformers/all-MiniLM-L6-v2' => 384,
            'sentence-transformers/all-mpnet-base-v2' => 768,
        ];

        return $dimensions[$model] ?? 768;
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        $this->assertModel($options);
        $this->assertApiKey();

        try {
            $model = $options['model'];
            $subProvider = $this->resolveSubProvider($options);

            $this->logger->info('HuggingFace image generation request', [
                'model' => $model,
                'sub_provider' => $subProvider,
                'prompt_length' => strlen($prompt),
            ]);

            $body = ['inputs' => $prompt];
            $parameters = $this->buildImageParameters($options);
            if ([] !== $parameters) {
                $body['parameters'] = $parameters;
            }

            $response = $this->httpClient->request('POST', $this->buildSubProviderModelUrl($subProvider, $model), [
                'headers' => $this->buildAuthHeaders(),
                'json' => $body,
                'timeout' => self::TIMEOUT_IMAGE_SECONDS,
            ]);

            $this->assertNotPaymentRequired($response, 'image generation');

            $imageData = $response->getContent();
            $mimeType = $this->normalizeImageMimeType($response->getHeaders()['content-type'][0] ?? 'image/png');
            $dataUrl = sprintf('data:%s;base64,%s', $mimeType, base64_encode($imageData));

            $this->logger->info('HuggingFace image generated', [
                'model' => $model,
                'size_bytes' => strlen($imageData),
                'mime_type' => $mimeType,
            ]);

            return [[
                'url' => $dataUrl,
                'revised_prompt' => $prompt,
            ]];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace image generation error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);
            throw new ProviderException('HuggingFace image generation error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Image variations not supported by HuggingFace provider', self::PROVIDER_NAME);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        // FLUX-Kontext via fal.ai performs image-to-image edits guided by a text prompt.
        // The mask URL is currently ignored because the underlying model does not accept
        // explicit inpaint masks - it derives edit regions from the prompt instead.
        $this->assertApiKey();

        try {
            $imageBase64 = $this->loadImageAsBase64($imageUrl);

            $this->logger->info('HuggingFace image edit request', [
                'model' => self::DEFAULT_IMAGE_EDIT_MODEL,
                'prompt' => $prompt,
            ]);

            $url = $this->buildSubProviderModelUrl(self::FAL_PROVIDER, self::DEFAULT_IMAGE_EDIT_MODEL);

            $response = $this->httpClient->request('POST', $url, [
                'headers' => $this->buildAuthHeaders(),
                'json' => [
                    'inputs' => $imageBase64,
                    'parameters' => [
                        'prompt' => $prompt,
                        'guidance_scale' => self::DEFAULT_IMAGE_EDIT_GUIDANCE_SCALE,
                    ],
                ],
                'timeout' => self::TIMEOUT_IMAGE_SECONDS,
            ]);

            $this->assertNotPaymentRequired($response, 'image edit');

            $relativePath = 'generated/hf_edit_'.bin2hex(random_bytes(8)).'.png';
            $this->writeUploadedFile($relativePath, $response->getContent());

            return $relativePath;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace image edit error', [
                'error' => $e->getMessage(),
            ]);
            throw new ProviderException('HuggingFace image edit error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== VIDEO GENERATION ====================

    /**
     * Generate a video from a text prompt using fal.ai via the HuggingFace router.
     *
     * Supported models via fal-ai (pass slug as $options['model']):
     * - fal-ai/ltx-video      (fast, recommended)
     * - fal-ai/hunyuan-video  (higher quality, slower)
     *
     * fal.ai video models require queue-based processing (submit -> poll -> result)
     * because generation typically exceeds synchronous timeout limits.
     *
     * @param array<string, mixed> $options model (required), num_inference_steps, seed,
     *                                      negative_prompt, guidance_scale, resolution
     *
     * @return array<int, array{url: string, seed: ?int, resolution: ?string}>
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $this->assertModel($options);
        $this->assertApiKey();

        try {
            $model = $options['model'];
            $falModelId = $this->buildFalModelId($model);

            $this->logger->info('HuggingFace video generation request (queue)', [
                'model' => $model,
                'fal_model_id' => $falModelId,
                'prompt_length' => strlen($prompt),
            ]);

            $submitData = $this->submitFalVideoJob($falModelId, $this->buildVideoRequestBody($prompt, $options));

            $this->logger->info('HuggingFace video queued', [
                'request_id' => $submitData['request_id'],
                'fal_model_id' => $falModelId,
                'status' => $submitData['status'] ?? 'unknown',
            ]);

            $this->pollUntilComplete($submitData['status_url'], $submitData['request_id'], $falModelId);

            $resultResponse = $this->httpClient->request('GET', $submitData['response_url'], [
                'headers' => $this->buildAuthHeaders(),
                'timeout' => self::TIMEOUT_VIDEO_RESULT_SECONDS,
            ]);

            $data = $resultResponse->toArray();

            if (!isset($data['video']['url'])) {
                throw new ProviderException('fal.ai response missing video URL: '.json_encode(array_keys($data)), self::PROVIDER_NAME);
            }

            $this->logger->info('HuggingFace video generated', [
                'model' => $model,
                'video_url' => $data['video']['url'],
                'seed' => $data['seed'] ?? null,
            ]);

            return [[
                'url' => $data['video']['url'],
                'seed' => $data['seed'] ?? null,
                'resolution' => $options['resolution'] ?? null,
            ]];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('HuggingFace video generation error', [
                'error' => $e->getMessage(),
                'model' => $options['model'],
            ]);
            throw new ProviderException('HuggingFace video generation error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== PRECONDITIONS ====================

    /**
     * @param array<string, mixed> $options
     */
    private function assertChatPreconditions(array $messages, array $options): void
    {
        $this->assertModel($options);
        $this->assertApiKey();

        if ([] === $messages) {
            throw new ProviderException('Messages array must not be empty', self::PROVIDER_NAME);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function assertModel(array $options): void
    {
        if (!isset($options['model']) || '' === $options['model']) {
            throw new ProviderException('Model must be specified in options', self::PROVIDER_NAME);
        }
    }

    private function assertApiKey(): void
    {
        if (empty($this->apiKey)) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, 'HUGGINGFACE_API_KEY');
        }
    }

    private function assertNotPaymentRequired(ResponseInterface $response, string $action): void
    {
        if (402 === $response->getStatusCode()) {
            throw new ProviderException(sprintf('HuggingFace %s requires prepaid credits. Add credits at %s', $action, self::BILLING_URL), self::PROVIDER_NAME);
        }
    }

    /**
     * Assert the HTTP response from a streaming chat request is healthy
     * BEFORE we start consuming its SSE body.
     *
     * Symfony's HttpClient is lazy – calling getStatusCode() forces the
     * request to actually fly so we can detect 402/4xx/5xx upfront. Without
     * this check, errors would surface as empty SSE streams and the caller
     * would see a phantom successful completion with no content.
     */
    private function assertChatResponseHttpOk(ResponseInterface $response): void
    {
        $this->assertNotPaymentRequired($response, 'streaming chat');

        $statusCode = $response->getStatusCode();
        if ($statusCode >= 200 && $statusCode < 300) {
            return;
        }

        // Best-effort: read the error body for a useful message. Fail open
        // if the body cannot be read so we never mask the original failure.
        $body = '';
        try {
            $body = $response->getContent(false);
        } catch (\Throwable) {
        }

        throw new ProviderException(
            sprintf('HuggingFace streaming chat failed (HTTP %d): %s', $statusCode, '' !== $body ? $body : 'no response body'),
            self::PROVIDER_NAME,
        );
    }

    // ==================== CHAT HELPERS ====================

    /**
     * @param array<int, array<string, mixed>> $messages
     * @param array<string, mixed>             $options
     *
     * @return array<string, mixed>
     */
    private function buildChatRequestBody(string $model, array $messages, array $options, bool $stream): array
    {
        $body = [
            'model' => $model,
            'messages' => $messages,
            'stream' => $stream,
        ];

        if ($stream) {
            $body['stream_options'] = ['include_usage' => true];
        }

        foreach (self::FORWARDABLE_CHAT_OPTIONS as $key) {
            if (array_key_exists($key, $options)) {
                $body[$key] = $options[$key];
            }
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function postChatCompletion(array $body): array
    {
        $response = $this->httpClient->request('POST', self::CHAT_ENDPOINT, [
            'headers' => $this->buildAuthHeaders(),
            'json' => $body,
            'timeout' => self::TIMEOUT_CHAT_SECONDS,
        ]);

        $this->assertNotPaymentRequired($response, 'chat completion');

        return $response->toArray();
    }

    /**
     * Consume an SSE chat stream and dispatch content chunks to the callback.
     *
     * Uses a line buffer to safely reassemble `data: ...` events that may arrive
     * split across multiple HTTP chunks.
     *
     * @return array{0: array<string, int>, 1: ?string, 2: int} [usage, finishReason, chunkCount]
     */
    private function consumeChatStream(ResponseInterface $response, callable $callback): array
    {
        $usage = $this->parseUsage([]);
        $finishReason = null;
        $chunkCount = 0;
        $buffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            // SSE events are separated by "\n"; keep the trailing partial line in the buffer
            $lastNewline = strrpos($buffer, "\n");
            if (false === $lastNewline) {
                continue;
            }

            $complete = substr($buffer, 0, $lastNewline);
            $buffer = substr($buffer, $lastNewline + 1);

            foreach (explode("\n", $complete) as $line) {
                $line = trim($line);
                if ('' === $line || !str_starts_with($line, 'data: ')) {
                    continue;
                }

                $payload = substr($line, 6);
                if ('[DONE]' === $payload) {
                    break 2;
                }

                $json = json_decode($payload, true);
                if (!is_array($json)) {
                    continue;
                }

                if (isset($json['usage']) && is_array($json['usage'])) {
                    $usage = $this->parseUsage($json['usage']);
                }

                $choice = $json['choices'][0] ?? [];
                if (isset($choice['finish_reason'])) {
                    $finishReason = (string) $choice['finish_reason'];
                }

                $delta = $choice['delta']['content'] ?? '';
                if ('' !== $delta) {
                    ++$chunkCount;
                    $callback($delta);
                }
            }
        }

        return [$usage, $finishReason, $chunkCount];
    }

    /**
     * @param array<string, mixed> $rawUsage
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}
     */
    private function parseUsage(array $rawUsage): array
    {
        return [
            'prompt_tokens' => (int) ($rawUsage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($rawUsage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($rawUsage['total_tokens'] ?? 0),
            'cached_tokens' => 0,
            'cache_creation_tokens' => 0,
        ];
    }

    private function logChatError(string $kind, \Throwable $e, mixed $model): void
    {
        $this->logger->error('HuggingFace '.$kind.' error', [
            'error' => $e->getMessage(),
            'model' => $model,
        ]);
    }

    // ==================== VISION HELPERS ====================

    /**
     * Build a single multimodal `user` message containing a text part and one or more images.
     *
     * @param array<int, string> $imageDataUrls each entry must be a `data:` URL
     *
     * @return array<string, mixed>
     */
    private function buildVisionMessage(string $text, array $imageDataUrls): array
    {
        $content = [['type' => 'text', 'text' => $text]];
        foreach ($imageDataUrls as $url) {
            $content[] = ['type' => 'image_url', 'image_url' => ['url' => $url]];
        }

        return ['role' => 'user', 'content' => $content];
    }

    // ==================== EMBEDDING / IMAGE HELPERS ====================

    /**
     * Map the Synaplan service alias ("HuggingFace"/"huggingface") onto the actual
     * HF Inference Provider key (`hf-inference`). Other strings (e.g. `together`,
     * `fireworks-ai`, `sambanova`) are passed through untouched.
     *
     * @param array<string, mixed> $options
     */
    private function resolveSubProvider(array $options): string
    {
        $provider = $options['provider'] ?? self::DEFAULT_SUB_PROVIDER;
        if (!is_string($provider) || '' === $provider) {
            return self::DEFAULT_SUB_PROVIDER;
        }

        return in_array(strtolower($provider), ['huggingface', 'hugging face'], true)
            ? self::DEFAULT_SUB_PROVIDER
            : $provider;
    }

    private function buildSubProviderModelUrl(string $subProvider, string $model): string
    {
        return self::ROUTER_BASE."/{$subProvider}/models/{$model}";
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<int|string, mixed>
     */
    private function postEmbedding(string $url, array $body, int $timeoutSeconds): array
    {
        $response = $this->httpClient->request('POST', $url, [
            'headers' => $this->buildAuthHeaders(),
            'json' => $body,
            'timeout' => $timeoutSeconds,
        ]);

        $this->assertNotPaymentRequired($response, 'embedding');

        return $response->toArray();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildImageParameters(array $options): array
    {
        $intKeys = ['width', 'height', 'num_inference_steps', 'seed'];
        $floatKeys = ['guidance_scale'];
        $stringKeys = ['negative_prompt'];

        $parameters = [];
        foreach ($intKeys as $key) {
            if (isset($options[$key])) {
                $parameters[$key] = (int) $options[$key];
            }
        }
        foreach ($floatKeys as $key) {
            if (isset($options[$key])) {
                $parameters[$key] = (float) $options[$key];
            }
        }
        foreach ($stringKeys as $key) {
            if (isset($options[$key])) {
                $parameters[$key] = (string) $options[$key];
            }
        }

        return $parameters;
    }

    private function normalizeImageMimeType(string $rawContentType): string
    {
        if (str_contains($rawContentType, 'jpeg') || str_contains($rawContentType, 'jpg')) {
            return 'image/jpeg';
        }
        if (str_contains($rawContentType, 'webp')) {
            return 'image/webp';
        }

        return 'image/png';
    }

    // ==================== FILE HELPERS ====================

    /**
     * Resolve and read an upload-relative file, guarding against path traversal.
     */
    private function readUploadedFile(string $relativePath): string
    {
        $normalizedPath = $this->uploadDir.'/'.ltrim($relativePath, '/');
        $realPath = realpath($normalizedPath);
        $realUploadDir = realpath($this->uploadDir);

        if (false === $realPath || false === $realUploadDir || !str_starts_with($realPath, $realUploadDir)) {
            throw new ProviderException("Invalid image path: {$relativePath}", self::PROVIDER_NAME);
        }

        $contents = @file_get_contents($realPath);
        if (false === $contents) {
            throw new ProviderException("Failed to read image file: {$realPath}", self::PROVIDER_NAME);
        }

        return $contents;
    }

    private function loadImageAsBase64(string $relativePath): string
    {
        return base64_encode($this->readUploadedFile($relativePath));
    }

    private function loadImageAsDataUrl(string $relativePath): string
    {
        $contents = $this->readUploadedFile($relativePath);
        $realPath = realpath($this->uploadDir.'/'.ltrim($relativePath, '/'));
        $mimeType = (false !== $realPath ? mime_content_type($realPath) : false) ?: 'image/png';

        return sprintf('data:%s;base64,%s', $mimeType, base64_encode($contents));
    }

    private function writeUploadedFile(string $relativePath, string $contents): void
    {
        $fullPath = $this->uploadDir.'/'.ltrim($relativePath, '/');
        $dir = dirname($fullPath);

        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Failed to create directory "%s".', $dir));
        }

        if (false === file_put_contents($fullPath, $contents)) {
            throw new \RuntimeException(sprintf('Failed to write file to "%s".', $fullPath));
        }
    }

    // ==================== VIDEO HELPERS ====================

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildVideoRequestBody(string $prompt, array $options): array
    {
        $body = [
            'prompt' => $prompt,
            'num_inference_steps' => (int) ($options['num_inference_steps'] ?? self::DEFAULT_VIDEO_INFERENCE_STEPS),
        ];

        if (isset($options['seed'])) {
            $body['seed'] = (int) $options['seed'];
        }
        if (isset($options['negative_prompt'])) {
            $body['negative_prompt'] = (string) $options['negative_prompt'];
        }
        if (isset($options['guidance_scale'])) {
            $body['guidance_scale'] = (float) $options['guidance_scale'];
        }

        return $body;
    }

    /**
     * Submit a fal.ai video job via the HF router queue endpoint.
     *
     * The `?_subdomain=queue` parameter mirrors the official HuggingFace Python/JS SDKs
     * and routes the request to fal.ai's async queue.
     *
     * @param array<string, mixed> $body
     *
     * @return array{request_id: string, status_url: string, response_url: string, status?: string}
     */
    private function submitFalVideoJob(string $falModelId, array $body): array
    {
        $submitUrl = self::ROUTER_BASE.'/'.self::FAL_PROVIDER.'/'.$falModelId.'?_subdomain=queue';
        $response = $this->httpClient->request('POST', $submitUrl, [
            'headers' => $this->buildAuthHeaders(),
            'json' => $body,
            'timeout' => self::TIMEOUT_VIDEO_SUBMIT_SECONDS,
        ]);

        $this->assertNotPaymentRequired($response, 'video generation');

        $data = $response->toArray();
        $requestId = $data['request_id'] ?? null;
        $statusUrl = $data['status_url'] ?? null;
        $responseUrl = $data['response_url'] ?? null;

        if (!is_string($requestId) || !is_string($statusUrl) || !is_string($responseUrl)) {
            throw new ProviderException('fal.ai queue submit missing request_id or URLs: '.json_encode(array_keys($data)), self::PROVIDER_NAME);
        }

        return [
            'request_id' => $requestId,
            'status_url' => $statusUrl,
            'response_url' => $responseUrl,
            'status' => isset($data['status']) ? (string) $data['status'] : null,
        ];
    }

    /**
     * Poll fal.ai queue status until the request completes or fails.
     */
    private function pollUntilComplete(string $statusUrl, string $requestId, string $falModelId): void
    {
        for ($attempt = 0; $attempt < self::QUEUE_MAX_POLL_ATTEMPTS; ++$attempt) {
            sleep(self::QUEUE_POLL_INTERVAL_SECONDS);

            $statusResponse = $this->httpClient->request('GET', $statusUrl, [
                'headers' => $this->buildAuthHeaders(),
                'timeout' => self::TIMEOUT_VIDEO_POLL_SECONDS,
            ]);

            $statusData = $statusResponse->toArray();
            $status = (string) ($statusData['status'] ?? 'UNKNOWN');

            if ('COMPLETED' === $status) {
                $this->logger->info('HuggingFace video queue completed', [
                    'request_id' => $requestId,
                    'attempt' => $attempt + 1,
                ]);

                return;
            }

            if ('FAILED' === $status) {
                $error = $statusData['error'] ?? 'Unknown queue error';
                throw new ProviderException("fal.ai video generation failed: {$error}", self::PROVIDER_NAME);
            }

            if (0 === $attempt % self::QUEUE_POLL_LOG_EVERY) {
                $this->logger->debug('HuggingFace video queue polling', [
                    'request_id' => $requestId,
                    'status' => $status,
                    'attempt' => $attempt + 1,
                    'fal_model_id' => $falModelId,
                ]);
            }
        }

        throw new ProviderException(sprintf('fal.ai video generation timed out after %d seconds', self::QUEUE_POLL_INTERVAL_SECONDS * self::QUEUE_MAX_POLL_ATTEMPTS), self::PROVIDER_NAME);
    }

    /**
     * Build the fal.ai model identifier (e.g. "fal-ai/ltx-video").
     */
    private function buildFalModelId(string $model): string
    {
        return str_contains($model, '/') ? $model : self::FAL_PROVIDER.'/'.$model;
    }

    // ==================== GENERIC HELPERS ====================

    /**
     * @return array<string, string>
     */
    private function buildAuthHeaders(bool $sse = false): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->apiKey,
            'Content-Type' => 'application/json',
        ];

        if ($sse) {
            $headers['Accept'] = 'text/event-stream';
        }

        return $headers;
    }

    /**
     * Build the chat-completions model string with optional provider strategy suffix.
     *
     * Examples:
     *  - "moonshotai/Kimi-K2.6"                  (router picks provider automatically)
     *  - "moonshotai/Kimi-K2.6:fastest"          (router picks the fastest provider)
     *  - "moonshotai/Kimi-K2.6:together"         (route through the Together provider)
     *
     * @param array<string, mixed> $options may contain `provider_strategy`
     */
    private function buildModelString(string $model, array $options): string
    {
        if (str_contains($model, ':')) {
            return $model;
        }

        if (isset($options['provider_strategy']) && '' !== $options['provider_strategy']) {
            return $model.':'.$options['provider_strategy'];
        }

        return $model;
    }
}
