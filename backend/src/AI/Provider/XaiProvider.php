<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SupportsAsyncVideo;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * xAI (Grok) provider — chat, image understanding, and Grok Imagine media.
 *
 * Chat and vision run against the OpenAI-compatible `/v1/chat/completions`
 * endpoint, so they reuse the openai-php client with a custom base URI (SSE
 * parsing comes for free). Grok Imagine (`/v1/images/generations`,
 * `/v1/videos/*`) is NOT OpenAI-shaped — it uses xAI-specific fields
 * (`aspect_ratio`, `resolution`, `duration`) and an async submit → poll →
 * download lifecycle for video — so those calls go through the plain HTTP
 * client.
 *
 * @see https://docs.x.ai/developers/rest-api-reference/inference/chat
 * @see https://docs.x.ai/developers/model-capabilities/imagine
 */
final class XaiProvider implements ChatProviderInterface, ImageGenerationProviderInterface, SupportsAsyncVideo, VideoGenerationProviderInterface, VisionProviderInterface
{
    private const PROVIDER_NAME = 'xai';
    private const DISPLAY_NAME = 'xAI';
    private const ENV_VAR = 'XAI_API_KEY';

    private const BASE_URI = 'https://api.x.ai/v1';

    private const DEFAULT_CHAT_MODEL = 'grok-4.3';
    private const DEFAULT_VISION_MODEL = 'grok-4.5';
    private const DEFAULT_IMAGE_MODEL = 'grok-imagine-image';
    private const DEFAULT_VIDEO_MODEL = 'grok-imagine-video';

    private const VISION_MAX_TOKENS = 2048;

    /**
     * xAI rejects images above 20 MiB and anything other than JPEG/PNG. We
     * check locally so the user gets a readable message instead of a raw 4xx.
     *
     * @see https://docs.x.ai/developers/models
     */
    private const MAX_IMAGE_BYTES = 20 * 1024 * 1024;
    private const SUPPORTED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png'];

    private const REASONING_EFFORTS = ['none', 'low', 'medium', 'high'];

    /**
     * Model families that accept `reasoning_effort: none`. grok-4.5 cannot
     * disable reasoning at all, so its cheapest tier is `low`; sending `none`
     * there is rejected by the API.
     */
    private const EFFORT_NONE_MODELS = ['grok-4.3'];

    private const IMAGE_MIME_FALLBACK = 'image/png';
    private const MAX_IMAGES_PER_REQUEST = 10;

    private const VIDEO_MIN_DURATION = 1;
    private const VIDEO_MAX_DURATION = 15;
    private const VIDEO_DEFAULT_DURATION = 8;
    private const VIDEO_RESOLUTIONS = ['480p', '720p', '1080p'];

    private const TIMEOUT_SUBMIT_SECONDS = 60;
    private const TIMEOUT_POLL_SECONDS = 15;
    private const TIMEOUT_DOWNLOAD_SECONDS = 300;

    private const POLL_INTERVAL_SECONDS = 5;

    /** Cap on poll attempts in the blocking path. 5s × 180 = 15 min. */
    private const POLL_MAX_ATTEMPTS = 180;

    /**
     * Rough render budget (seconds) used only when xAI omits `progress`, so the
     * UI still gets a monotonic bar instead of a frozen 0%.
     */
    private const ESTIMATED_VIDEO_SECONDS = 120;

    private mixed $client = null;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
        // Injectable so unit tests can poll without real sleeps.
        private readonly int $pollIntervalSeconds = self::POLL_INTERVAL_SECONDS,
    ) {
        if (!empty($apiKey)) {
            $this->client = \OpenAI::factory()
                ->withApiKey($apiKey)
                ->withBaseUri(self::BASE_URI)
                ->make();
        }
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
        return 'xAI Grok — long-context chat with configurable reasoning and tool calling, image understanding, plus Grok Imagine image and video generation.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'image_generation', 'video_generation'];
    }

    public function getDefaultModels(): array
    {
        return [
            'chat' => self::DEFAULT_CHAT_MODEL,
            'vision' => self::DEFAULT_VISION_MODEL,
            'image_generation' => self::DEFAULT_IMAGE_MODEL,
            'video_generation' => self::DEFAULT_VIDEO_MODEL,
        ];
    }

    public function getStatus(): array
    {
        if (!$this->isAvailable()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 0,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return !empty($this->apiKey) && null !== $this->client;
    }

    public function getRequiredEnvVars(): array
    {
        return [
            self::ENV_VAR => [
                'required' => true,
                'hint' => 'Get your API key from https://console.x.ai/ (Team → API Keys)',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
    {
        $this->assertChat($options);

        try {
            $requestOptions = $this->buildChatOptions($messages, $options, false);
            $response = $this->client->chat()->create($requestOptions);
            $responseArray = $response->toArray();

            return [
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => $this->parseUsage($responseArray['usage'] ?? []),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('xAI chat error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('xAI chat error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        $this->assertChat($options);

        try {
            $requestOptions = $this->buildChatOptions($messages, $options, true);
            $stream = $this->client->chat()->createStreamed($requestOptions);

            $usage = $this->parseUsage([]);
            $finishReason = null;

            foreach ($stream as $response) {
                $responseArray = $response->toArray();

                if (isset($responseArray['usage'])) {
                    $usage = $this->parseUsage($responseArray['usage']);
                }

                $chunkFinishReason = $responseArray['choices'][0]['finish_reason'] ?? null;
                if (null !== $chunkFinishReason) {
                    $finishReason = $chunkFinishReason;
                }

                $this->dispatchStreamDelta($responseArray, $callback);
            }

            $callback(['type' => 'finish', 'finish_reason' => $finishReason ?? 'stop']);

            return ['usage' => $usage];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            $this->logger->error('xAI streaming error', [
                'error' => $e->getMessage(),
                'model' => $options['model'] ?? 'unknown',
            ]);

            throw new ProviderException('xAI streaming error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        $this->assertApiKey();

        $model = $options['model'] ?? self::DEFAULT_VISION_MODEL;
        $prompt = '' !== $prompt ? $prompt : 'Please describe this image in detail.';

        try {
            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl)]],
                    ],
                ]],
                'max_completion_tokens' => $options['max_tokens'] ?? self::VISION_MAX_TOKENS,
            ]);

            return $response->choices[0]->message->content ?? '';
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('xAI vision error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->explainImage(
            $imageUrl,
            'Extract all text from this image. Provide only the extracted text without any commentary.',
        );
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        $this->assertApiKey();

        try {
            $response = $this->client->chat()->create([
                'model' => self::DEFAULT_VISION_MODEL,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => 'Compare these two images and describe the differences and similarities.'],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl1)]],
                        ['type' => 'image_url', 'image_url' => ['url' => $this->imageToDataUrl($imageUrl2)]],
                    ],
                ]],
                'max_completion_tokens' => self::VISION_MAX_TOKENS,
            ]);

            return [
                'comparison' => $response->choices[0]->message->content ?? '',
                'image1' => basename($imageUrl1),
                'image2' => basename($imageUrl2),
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('xAI image comparison error: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    // ==================== IMAGE GENERATION (Grok Imagine) ====================

    /**
     * @param array{
     *   model?: string,
     *   n?: int,
     *   aspect_ratio?: string,
     *   modelConfig?: array<string, mixed>,
     * } $options
     *
     * @return array<int, array{url: string, b64_json: string, revised_prompt: null}>
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $this->assertApiKey();

        $model = $this->modelFromOptions($options, self::DEFAULT_IMAGE_MODEL);
        $modelConfig = $this->modelConfigFromOptions($options);

        // b64_json rather than url: xAI's image URLs are short-lived and we
        // persist the bytes into BFILES ourselves anyway.
        $body = [
            'model' => $model,
            'prompt' => $prompt,
            'n' => $this->imageCountFromOptions($options),
            'response_format' => 'b64_json',
        ];

        $aspectRatio = $this->aspectRatioFromOptions($options, $modelConfig);
        if (null !== $aspectRatio) {
            $body['aspect_ratio'] = $aspectRatio;
        }

        $this->logger->info('xAI: generateImage', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'n' => $body['n'],
            'aspect_ratio' => $aspectRatio,
        ]);

        $data = $this->requestJson('POST', self::BASE_URI.'/images/generations', $body, self::TIMEOUT_SUBMIT_SECONDS);

        return $this->parseImagePayload($data);
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('xAI Grok Imagine has no image-variation endpoint. Use image editing with an explicit prompt instead.', self::PROVIDER_NAME);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        // /v1/images/edits exists but bills the input image separately
        // ($0.002/img), which the per_image usage path cannot attribute yet, so
        // we would silently under-bill. Kept out until the cost path supports it.
        throw new ProviderException('xAI Grok Imagine image editing is not enabled in Synaplan yet (input-image billing is not tracked).', self::PROVIDER_NAME);
    }

    // ==================== VIDEO GENERATION (Grok Imagine) ====================

    /**
     * Blocking submit → poll → return. Used by the legacy in-request media path;
     * the chat flow prefers the async job methods below.
     *
     * @param array{
     *   model?: string,
     *   prompt?: string,
     *   duration?: int|string,
     *   resolution?: string,
     *   aspect_ratio?: string,
     *   image_url?: string,
     *   images?: array<int, string>,
     *   progress_callback?: callable,
     *   cancel_check?: callable,
     *   modelConfig?: array<string, mixed>,
     * } $options
     *
     * @return array<int, array{url: string, duration: int, resolution: string|null}>
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $this->assertApiKey();

        $model = $this->modelFromOptions($options, self::DEFAULT_VIDEO_MODEL);
        $body = $this->buildVideoRequestBody($prompt, $model, $options);
        $requestId = $this->submitVideo($body);

        $progressCallback = $this->callableFromOptions($options, 'progress_callback');
        $cancelCheck = $this->callableFromOptions($options, 'cancel_check');

        $handle = $this->encodeOperation($requestId, $body);
        $startedAt = time();

        for ($attempt = 1; $attempt <= self::POLL_MAX_ATTEMPTS; ++$attempt) {
            // A video render takes minutes and FrankenPHP's max_execution_time
            // is wall-clock, so re-arm a per-iteration budget (set_time_limit
            // restarts the counter) instead of being killed mid-render.
            $this->extendExecutionTime($this->pollIntervalSeconds + self::TIMEOUT_POLL_SECONDS + 30);

            if (null !== $cancelCheck && $cancelCheck()) {
                // xAI has no cancel endpoint — the render (and its cost) keeps
                // running upstream; we only stop waiting for it.
                $this->logger->info('xAI: video generation abandoned after cancel request', ['request_id' => $requestId]);

                throw new ProviderCancelledException('xAI video generation cancelled', self::PROVIDER_NAME, ['request_id' => $requestId]);
            }

            if ($this->pollIntervalSeconds > 0) {
                sleep($this->pollIntervalSeconds);
            }

            $result = $this->pollVideoOperationOnce($handle, $options);

            if (null !== $progressCallback && null !== $result['percent']) {
                $progressCallback($result['percent']);
            }

            if (!$result['done']) {
                continue;
            }

            if (null !== $result['error'] || null === $result['videoUri']) {
                throw new ProviderException('xAI video generation failed: '.($result['error'] ?? 'no media URL returned'), self::PROVIDER_NAME, ['request_id' => $requestId]);
            }

            return [[
                'url' => $result['videoUri'],
                'duration' => (int) $body['duration'],
                'resolution' => isset($body['resolution']) && is_string($body['resolution']) ? $body['resolution'] : null,
            ]];
        }

        throw new ProviderException(sprintf('xAI video generation timed out after %d seconds', time() - $startedAt), self::PROVIDER_NAME, ['request_id' => $requestId]);
    }

    /**
     * Submit a render and return an opaque handle without blocking.
     *
     * The handle is a JSON blob carrying the request id plus the submitted
     * duration/resolution, so a later stateless poll can report exactly what
     * was billed without re-reading the catalog.
     *
     * @param array<string, mixed> $options
     *
     * @return array{operationName: string, model: string, duration: int, resolution: ?string}
     */
    public function startVideoOperation(string $prompt, array $options = []): array
    {
        $this->assertApiKey();

        $model = $this->modelFromOptions($options, self::DEFAULT_VIDEO_MODEL);
        $body = $this->buildVideoRequestBody($prompt, $model, $options);

        $this->logger->info('xAI: startVideoOperation', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'duration' => $body['duration'],
            'resolution' => $body['resolution'] ?? null,
            'has_reference' => isset($body['image']),
        ]);

        $requestId = $this->submitVideo($body);

        return [
            'operationName' => $this->encodeOperation($requestId, $body),
            'model' => $model,
            'duration' => (int) $body['duration'],
            'resolution' => isset($body['resolution']) && is_string($body['resolution']) ? $body['resolution'] : null,
        ];
    }

    /**
     * Poll a submitted render exactly once, mapping xAI's deferred-video status
     * onto the provider-agnostic {done, videoUri, error, status, percent} shape.
     * Terminal failures are returned as `done: true` with an `error` so the
     * worker records a user-visible failure instead of retrying forever.
     *
     * @param array<string, mixed> $options
     *
     * @return array{done: bool, videoUri: ?string, error: ?string, status: string, percent: ?int}
     */
    public function pollVideoOperationOnce(string $operationName, array $options = []): array
    {
        $this->assertApiKey();

        $handle = $this->decodeOperation($operationName);
        $data = $this->requestJson('GET', self::BASE_URI.'/videos/'.rawurlencode($handle['request_id']), null, self::TIMEOUT_POLL_SECONDS);

        $status = is_string($data['status'] ?? null) ? $data['status'] : 'unknown';
        $progress = is_numeric($data['progress'] ?? null) ? (int) $data['progress'] : null;
        $video = is_array($data['video'] ?? null) ? $data['video'] : [];

        if ('done' === $status) {
            // A moderated-away video comes back "done" with an empty URL, so the
            // URL check alone would report a confusing "no media" error.
            if (false === ($video['respect_moderation'] ?? true)) {
                return [
                    'done' => true,
                    'videoUri' => null,
                    'error' => 'Content blocked by xAI safety filter',
                    'status' => $status,
                    'percent' => null,
                ];
            }

            $url = $video['url'] ?? null;
            $hasUrl = is_string($url) && '' !== $url;

            return [
                'done' => true,
                'videoUri' => $hasUrl ? $url : null,
                'error' => $hasUrl ? null : 'xAI reported the video as done without a media URL',
                'status' => $status,
                'percent' => 100,
            ];
        }

        if (in_array($status, ['failed', 'expired'], true)) {
            return [
                'done' => true,
                'videoUri' => null,
                'error' => $this->videoErrorMessage($data, $status),
                'status' => $status,
                'percent' => null,
            ];
        }

        return [
            'done' => false,
            'videoUri' => null,
            'error' => null,
            'status' => $status,
            'percent' => $progress ?? $this->estimatePercent(time() - $handle['started_at']),
        ];
    }

    /**
     * Download the produced video. xAI serves finished renders from a public
     * bucket URL, so no Authorization header is sent.
     *
     * @param array<string, mixed> $options
     */
    public function downloadVideoRaw(string $videoUri, array $options = []): string
    {
        try {
            $response = $this->httpClient->request('GET', $videoUri, [
                'timeout' => self::TIMEOUT_DOWNLOAD_SECONDS,
            ]);

            return $response->getContent();
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('xAI video download failed: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }
    }

    /**
     * xAI exposes no cancel endpoint for deferred video renders. Stopping a job
     * therefore only ends our polling — the render finishes upstream and stays
     * billable, which is why we log it rather than pretending it was cancelled.
     *
     * @param array<string, mixed> $options
     */
    public function cancelVideoOperation(string $operationName, array $options = []): void
    {
        $requestId = '';
        try {
            $requestId = $this->decodeOperation($operationName)['request_id'];
        } catch (ProviderException) {
            // Unparseable handle — nothing useful to log beyond the attempt.
        }

        $this->logger->info('xAI: cancel requested but not supported upstream; the render keeps running and stays billable', [
            'request_id' => $requestId,
        ]);
    }

    // ==================== HELPERS: CHAT ====================

    /**
     * @param array<string, mixed> $options
     */
    private function assertChat(array $options): void
    {
        if (!isset($options['model'])) {
            throw new ProviderException('Model must be specified in options', self::PROVIDER_NAME);
        }

        $this->assertApiKey();
    }

    private function assertApiKey(): void
    {
        if (null === $this->client) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, self::ENV_VAR);
        }
    }

    /**
     * Emit one streamed chunk's reasoning and content deltas.
     *
     * Both are read from the raw payload, NOT from the typed DTO: openai-php
     * maps the OpenAI-style `reasoning` field onto
     * CreateStreamedResponseDelta::$reasoningContent and the class is final
     * without __get, so xAI's `reasoning_content` would silently never be seen
     * through the object graph.
     *
     * @param array<string, mixed> $responseArray
     */
    private function dispatchStreamDelta(array $responseArray, callable $callback): void
    {
        $reasoning = $responseArray['choices'][0]['delta']['reasoning_content'] ?? null;
        if (is_string($reasoning) && '' !== $reasoning) {
            $callback([
                'type' => 'reasoning',
                'content' => $reasoning,
            ]);
        }

        $content = $responseArray['choices'][0]['delta']['content'] ?? null;
        if (is_string($content) && '' !== $content) {
            $callback($content);
        }
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $model = (string) $options['model'];

        $request = [
            'model' => $model,
            'messages' => $messages,
            // `max_tokens` is deprecated at xAI in favour of
            // `max_completion_tokens`, which excludes reasoning tokens.
            'max_completion_tokens' => $options['max_tokens'] ?? ChatProviderInterface::DEFAULT_MAX_COMPLETION_TOKENS,
        ];

        if (isset($options['temperature'])) {
            $request['temperature'] = $options['temperature'];
        }

        $effort = $this->resolveReasoningEffort($model, $options);
        if (null !== $effort) {
            $request['reasoning_effort'] = $effort;
        }

        // Sticky routing for xAI's prompt cache. Without a stable key a
        // follow-up turn often lands on a cache-cold server and the whole
        // prompt is billed at the full input rate instead of the cached one.
        $cacheKey = $options['cache_key'] ?? null;
        if (is_string($cacheKey) && '' !== $cacheKey) {
            $request['prompt_cache_key'] = $cacheKey;
        }

        if ($stream) {
            $request['stream'] = true;
            // Without include_usage the final chunk carries no token counts and
            // the usage statistics fall back to a byte-based estimate.
            $request['stream_options'] = ['include_usage' => true];
        }

        return $request;
    }

    /**
     * Resolve xAI's `reasoning_effort`.
     *
     * Mirrors {@see OpenAIProvider::resolveReasoningConfig()} so "default chat =
     * fast, Thinking toggle = deep" behaves the same across providers. This
     * matters more on xAI than elsewhere: grok-4.5 defaults to `high` server
     * side, so omitting the parameter would burn deep-reasoning tokens on every
     * classification call.
     *
     * Resolution order:
     *   1. Explicit `reasoning_effort` string wins.
     *   2. The Thinking toggle (`reasoning` bool): true → the catalog's
     *      `reasoning_effort_default` (or `high`), false → the cheapest tier the
     *      model accepts.
     *   3. No signal, or a model that does not advertise reasoning → null, so
     *      nothing is sent.
     *
     * @param array<string, mixed> $options
     */
    private function resolveReasoningEffort(string $model, array $options): ?string
    {
        $explicit = $options['reasoning_effort'] ?? null;
        if (is_string($explicit) && in_array(strtolower($explicit), self::REASONING_EFFORTS, true)) {
            return $this->clampEffort($model, strtolower($explicit));
        }

        if (!array_key_exists('reasoning', $options)) {
            return null;
        }

        // A model row without the `reasoning` feature (e.g. a vision-only row)
        // must never receive the parameter.
        $features = $options['modelFeatures'] ?? null;
        if (is_array($features) && !in_array('reasoning', $features, true)) {
            return null;
        }

        if (!$options['reasoning']) {
            return $this->lowestEffortTier($model);
        }

        $default = $this->modelConfigFromOptions($options)['reasoning_effort_default'] ?? null;
        if (is_string($default) && in_array($default, self::REASONING_EFFORTS, true)) {
            return $this->clampEffort($model, $default);
        }

        return 'high';
    }

    private function clampEffort(string $model, string $effort): string
    {
        if ('none' === $effort) {
            return $this->lowestEffortTier($model);
        }

        return $effort;
    }

    private function lowestEffortTier(string $model): string
    {
        foreach (self::EFFORT_NONE_MODELS as $family) {
            if (str_starts_with($model, $family)) {
                return 'none';
            }
        }

        return 'low';
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}
     */
    private function parseUsage(array $usage): array
    {
        $details = is_array($usage['prompt_tokens_details'] ?? null) ? $usage['prompt_tokens_details'] : [];

        // xAI counts reasoning tokens inside completion_tokens
        // (completion_tokens_details.reasoning_tokens is a breakdown, not an
        // addition), so they must not be added on top.
        return [
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'total_tokens' => (int) ($usage['total_tokens'] ?? 0),
            'cached_tokens' => (int) ($details['cached_tokens'] ?? 0),
            'cache_creation_tokens' => 0,
        ];
    }

    private function imageToDataUrl(string $imageUrl): string
    {
        if (str_starts_with($imageUrl, 'data:')
            || str_starts_with($imageUrl, 'http://')
            || str_starts_with($imageUrl, 'https://')) {
            return $imageUrl;
        }

        $fullPath = str_starts_with($imageUrl, '/')
            ? $imageUrl
            : $this->uploadDir.'/'.ltrim($imageUrl, '/');

        if (!is_file($fullPath)) {
            throw new ProviderException("Image file not found: {$fullPath}", self::PROVIDER_NAME);
        }

        $size = filesize($fullPath);
        if (false !== $size && $size > self::MAX_IMAGE_BYTES) {
            throw new ProviderException(sprintf('Image too large for xAI: %.1f MiB (limit %d MiB)', $size / 1024 / 1024, (int) (self::MAX_IMAGE_BYTES / 1024 / 1024)), self::PROVIDER_NAME);
        }

        $mimeType = mime_content_type($fullPath) ?: 'application/octet-stream';
        if (!in_array($mimeType, self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
            throw new ProviderException(sprintf('Unsupported image type for xAI: %s (supported: %s)', $mimeType, implode(', ', self::SUPPORTED_IMAGE_MIME_TYPES)), self::PROVIDER_NAME);
        }

        $imageData = file_get_contents($fullPath);
        if (false === $imageData) {
            throw new ProviderException("Failed to read image file: {$fullPath}", self::PROVIDER_NAME);
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($imageData);
    }

    // ==================== HELPERS: MEDIA ====================

    /**
     * @param array<string, mixed> $options
     */
    private function modelFromOptions(array $options, string $fallback): string
    {
        $model = $options['model'] ?? null;

        return is_string($model) && '' !== $model ? $model : $fallback;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function modelConfigFromOptions(array $options): array
    {
        return is_array($options['modelConfig'] ?? null) ? $options['modelConfig'] : [];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function callableFromOptions(array $options, string $key): ?callable
    {
        $value = $options[$key] ?? null;

        return is_callable($value) ? $value : null;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function imageCountFromOptions(array $options): int
    {
        $n = $options['n'] ?? 1;

        return max(1, min(self::MAX_IMAGES_PER_REQUEST, (int) $n));
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $modelConfig
     */
    private function aspectRatioFromOptions(array $options, array $modelConfig): ?string
    {
        foreach ([$options['aspect_ratio'] ?? null, $modelConfig['default_aspect_ratio'] ?? null] as $candidate) {
            if (is_string($candidate) && '' !== $candidate) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildVideoRequestBody(string $prompt, string $model, array $options): array
    {
        $modelConfig = $this->modelConfigFromOptions($options);

        $body = [
            'model' => $model,
            'duration' => $this->videoDurationFromOptions($options, $modelConfig),
        ];

        // Text-to-video needs a prompt; image-to-video may omit it. Keep the
        // key out of the payload when empty so xAI animates the still alone.
        if ('' !== $prompt) {
            $body['prompt'] = $prompt;
        }

        $resolution = $this->videoResolutionFromOptions($options, $modelConfig);
        if (null !== $resolution) {
            $body['resolution'] = $resolution;
        }

        $aspectRatio = $this->aspectRatioFromOptions($options, $modelConfig);
        if (null !== $aspectRatio) {
            $body['aspect_ratio'] = $aspectRatio;
        }

        $imageUrl = $this->videoReferenceImage($options);
        if (null !== $imageUrl) {
            $body['image'] = ['url' => $imageUrl];
        }

        if (!isset($body['prompt']) && !isset($body['image'])) {
            throw new ProviderException('xAI video generation needs either a prompt or a reference image.', self::PROVIDER_NAME);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function videoReferenceImage(array $options): ?string
    {
        $imageUrl = $options['image_url'] ?? null;
        if (!is_string($imageUrl) || '' === $imageUrl) {
            $images = $options['images'] ?? null;
            $imageUrl = is_array($images) && [] !== $images ? (string) reset($images) : null;
        }

        if (!is_string($imageUrl) || '' === $imageUrl) {
            return null;
        }

        // xAI accepts a public URL or a data URL; a local upload path has to be
        // inlined first.
        return $this->imageToDataUrl($imageUrl);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $modelConfig
     */
    private function videoDurationFromOptions(array $options, array $modelConfig): int
    {
        $requested = $options['duration'] ?? null;
        if (!is_numeric($requested)) {
            $requested = $modelConfig['default_duration'] ?? self::VIDEO_DEFAULT_DURATION;
        }

        $max = is_numeric($modelConfig['max_duration'] ?? null)
            ? min(self::VIDEO_MAX_DURATION, (int) $modelConfig['max_duration'])
            : self::VIDEO_MAX_DURATION;

        return max(self::VIDEO_MIN_DURATION, min($max, (int) $requested));
    }

    /**
     * Clamp the resolution to what the catalog row can price.
     *
     * `resolution_prices` is keyed by the values in `allowed_resolutions`; asking
     * xAI for anything outside that list would render fine but get billed at the
     * row's fallback rate, so we never forward an unpriceable value.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $modelConfig
     */
    private function videoResolutionFromOptions(array $options, array $modelConfig): ?string
    {
        $allowed = is_array($modelConfig['allowed_resolutions'] ?? null)
            ? array_values(array_filter($modelConfig['allowed_resolutions'], 'is_string'))
            : [];
        $effectiveAllowed = [] !== $allowed ? $allowed : self::VIDEO_RESOLUTIONS;

        $requested = $options['resolution'] ?? null;
        if (is_string($requested) && in_array($requested, $effectiveAllowed, true)) {
            return $requested;
        }

        $default = $modelConfig['default_resolution'] ?? null;
        if (is_string($default) && in_array($default, $effectiveAllowed, true)) {
            return $default;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function submitVideo(array $body): string
    {
        $data = $this->requestJson('POST', self::BASE_URI.'/videos/generations', $body, self::TIMEOUT_SUBMIT_SECONDS);

        $requestId = $data['request_id'] ?? null;
        if (!is_string($requestId) || '' === $requestId) {
            $this->logger->error('xAI: video submit response missing request_id', ['keys' => array_keys($data)]);

            throw new ProviderException('xAI video submit response missing request_id', self::PROVIDER_NAME);
        }

        return $requestId;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function encodeOperation(string $requestId, array $body): string
    {
        $handle = json_encode([
            'request_id' => $requestId,
            'model' => $body['model'] ?? '',
            'duration' => $body['duration'] ?? self::VIDEO_DEFAULT_DURATION,
            'resolution' => $body['resolution'] ?? null,
            'started_at' => time(),
        ]);

        return false === $handle ? '' : $handle;
    }

    /**
     * @return array{request_id: string, model: string, duration: int, resolution: ?string, started_at: int}
     */
    private function decodeOperation(string $operationName): array
    {
        $decoded = json_decode($operationName, true);
        if (!is_array($decoded) || !isset($decoded['request_id']) || !is_string($decoded['request_id']) || '' === $decoded['request_id']) {
            throw new ProviderException('Invalid xAI video operation handle', self::PROVIDER_NAME);
        }

        return [
            'request_id' => $decoded['request_id'],
            'model' => isset($decoded['model']) && is_string($decoded['model']) ? $decoded['model'] : '',
            'duration' => isset($decoded['duration']) && is_numeric($decoded['duration']) ? (int) $decoded['duration'] : self::VIDEO_DEFAULT_DURATION,
            'resolution' => isset($decoded['resolution']) && is_string($decoded['resolution']) ? $decoded['resolution'] : null,
            'started_at' => isset($decoded['started_at']) && is_numeric($decoded['started_at']) ? (int) $decoded['started_at'] : time(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function videoErrorMessage(array $data, string $status): string
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = $error['message'] ?? null;

        if (is_string($message) && '' !== $message) {
            return $message;
        }

        return sprintf('xAI video generation %s', $status);
    }

    private function estimatePercent(int $elapsed): int
    {
        $pct = (int) floor(($elapsed / self::ESTIMATED_VIDEO_SECONDS) * 100);

        return max(1, min(95, $pct));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, array{url: string, b64_json: string, revised_prompt: null}>
     */
    private function parseImagePayload(array $data): array
    {
        $items = $data['data'] ?? null;
        if (!is_array($items) || [] === $items) {
            $this->logger->error('xAI: image response missing data array', ['keys' => array_keys($data)]);

            throw new ProviderException('xAI returned no images', self::PROVIDER_NAME);
        }

        $images = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $b64 = $item['b64_json'] ?? null;
            if (!is_string($b64) || '' === $b64) {
                continue;
            }

            $mime = is_string($item['mime_type'] ?? null) && '' !== $item['mime_type']
                ? $item['mime_type']
                : self::IMAGE_MIME_FALLBACK;

            $images[] = [
                'url' => 'data:'.$mime.';base64,'.$b64,
                'b64_json' => $b64,
                'revised_prompt' => null,
            ];
        }

        if ([] === $images) {
            throw new ProviderException('xAI returned no usable image payload', self::PROVIDER_NAME);
        }

        return $images;
    }

    // ==================== HELPERS: HTTP ====================

    /**
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function requestJson(string $method, string $url, ?array $body, int $timeout): array
    {
        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer '.$this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'timeout' => $timeout,
        ];

        if (null !== $body) {
            $requestOptions['json'] = $body;
        }

        try {
            $response = $this->httpClient->request($method, $url, $requestOptions);
            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('xAI request failed: '.$e->getMessage(), self::PROVIDER_NAME, ['url' => $url], 0, $e);
        }

        if ($statusCode >= 400) {
            $this->handleErrorResponse($statusCode, $data);
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function handleErrorResponse(int $statusCode, array $data): never
    {
        $error = is_array($data['error'] ?? null) ? $data['error'] : [];
        $message = (string) ($error['message'] ?? $data['error'] ?? $data['message'] ?? $data['detail'] ?? 'Unknown error');

        if (401 === $statusCode || 403 === $statusCode) {
            throw new ProviderException("xAI authentication error ({$statusCode}): {$message}. Check that ".self::ENV_VAR.' holds a valid key from console.x.ai and that your team has access to the model.', self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        if (402 === $statusCode) {
            throw new ProviderException('xAI account is out of credits. Top up at https://console.x.ai/.', self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        if (404 === $statusCode) {
            throw new ProviderException("xAI model or request not found ({$statusCode}): {$message}", self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        if (429 === $statusCode) {
            throw new ProviderException('xAI rate limit exceeded. Please try again in a moment.', self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        throw new ProviderException("xAI API error ({$statusCode}): {$message}", self::PROVIDER_NAME, ['status_code' => $statusCode, 'response' => $data]);
    }

    private function extendExecutionTime(int $seconds): void
    {
        if (\function_exists('set_time_limit')) {
            set_time_limit(max(30, $seconds));
        }
    }
}
