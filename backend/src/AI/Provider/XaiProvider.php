<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\SupportsAsyncVideo;
use App\AI\Interface\SupportsInlineReferenceImage;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\Concerns\ChatCompletionsToolSupport;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputSchema;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * xAI (Grok) provider — chat, image understanding, Grok Imagine media, and voice.
 *
 * Chat and vision run against the OpenAI-compatible `/v1/chat/completions`
 * endpoint, so they reuse the openai-php client with a custom base URI (SSE
 * parsing comes for free). Grok Imagine (`/v1/images/generations`,
 * `/v1/videos/*`) is NOT OpenAI-shaped — it uses xAI-specific fields
 * (`aspect_ratio`, `resolution`, `duration`) and an async submit → poll →
 * download lifecycle for video — so those calls go through the plain HTTP
 * client. The same is true for voice: xAI serves `/v1/tts` and `/v1/stt`
 * instead of OpenAI's `/v1/audio/speech` and `/v1/audio/transcriptions`.
 *
 * The realtime Speech-to-Speech API (`wss://api.x.ai/v1/realtime`) is
 * deliberately not implemented — this application has no realtime-voice
 * capability to plug it into, and its per-minute session billing has no
 * counterpart in the usage model.
 *
 * @see https://docs.x.ai/developers/rest-api-reference/inference/chat
 * @see https://docs.x.ai/developers/model-capabilities/imagine
 * @see https://docs.x.ai/developers/model-capabilities/audio/voice
 */
final class XaiProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, ImageGenerationProviderInterface, SpeechToTextProviderInterface, SupportsAsyncVideo, SupportsInlineReferenceImage, TextToSpeechProviderInterface, VideoGenerationProviderInterface, VisionProviderInterface
{
    use ChatCompletionsToolSupport;

    private const PROVIDER_NAME = 'xai';
    private const DISPLAY_NAME = 'xAI';
    private const ENV_VAR = 'XAI_API_KEY';

    private const BASE_URI = 'https://api.x.ai/v1';

    private const DEFAULT_CHAT_MODEL = 'grok-4.5';
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
     * Model families that accept `reasoning_effort`. xAI documents the parameter
     * for grok-4.3 only — every other Grok model reasons at a fixed depth, so
     * sending it there would be an unsupported field. No shipped catalog row
     * uses grok-4.3 today; the gate exists so an operator who points a model row
     * at it gets the correct request shape.
     *
     * @see https://docs.x.ai/developers/rest-api-reference/inference/chat
     */
    private const REASONING_EFFORT_MODELS = ['grok-4.3'];

    private const IMAGE_MIME_FALLBACK = 'image/png';
    private const MAX_IMAGES_PER_REQUEST = 10;

    /**
     * Output resolutions the Imagine image endpoint accepts. We always send one
     * explicitly: xAI's default is undocumented, and on the quality model the
     * resolution decides the per-image price.
     *
     * These are API-level lists — the union of what any Grok model accepts, used
     * only when a catalog row declares no `allowed_resolutions`. What is billable
     * per row is a narrower question, see resolutionFromOptions().
     *
     * @see https://docs.x.ai/developers/model-capabilities/images/generation
     */
    private const IMAGE_RESOLUTIONS = ['1k', '2k'];

    private const VIDEO_MIN_DURATION = 1;
    private const VIDEO_MAX_DURATION = 15;
    private const VIDEO_DEFAULT_DURATION = 8;

    /** 1080p is Grok Imagine Video 1.5 only; see IMAGE_RESOLUTIONS on the scope. */
    private const VIDEO_RESOLUTIONS = ['480p', '720p', '1080p'];

    /**
     * Voice endpoints. Both are xAI-specific paths, NOT the OpenAI-compatible
     * `/v1/audio/*` ones, and neither takes a `model` field — the endpoint
     * itself selects the model, so the catalog rows carry the documentation
     * names `grok-tts` / `grok-stt` purely as identifiers.
     *
     * @see https://docs.x.ai/developers/rest-api-reference/inference/voice
     */
    private const TTS_ENDPOINT = self::BASE_URI.'/tts';
    private const TTS_VOICES_ENDPOINT = self::BASE_URI.'/tts/voices';
    private const STT_ENDPOINT = self::BASE_URI.'/stt';

    private const TTS_MAX_CHARS = 15000;

    /** xAI's documented ceiling for `keyterm` biasing on /v1/stt. */
    private const STT_MAX_KEYTERMS = 100;
    private const STT_MAX_KEYTERM_LENGTH = 50;

    private const DEFAULT_TTS_VOICE = 'eve';
    private const DEFAULT_TTS_CODEC = 'mp3';

    /**
     * `/v1/tts` requires `language`; `auto` makes xAI detect it, which is what
     * we want because the caller's language hint is often absent.
     */
    private const DEFAULT_TTS_LANGUAGE = 'auto';

    /**
     * Minimal offline fallback roster, used only when `/v1/tts/voices` is
     * unreachable or no key is configured. xAI ships many more voices (plus
     * custom ones), so getVoices() always prefers the live list.
     */
    private const TTS_BUILTIN_VOICES = [
        'eve' => 'energetic',
        'ara' => 'warm',
        'rex' => 'confident',
        'sal' => 'balanced',
        'leo' => 'authoritative',
    ];

    /**
     * Codec → [file extension, MIME type], mirroring xAI's own codec table.
     * `pcm`, `mulaw` and `alaw` are HEADERLESS streams — they must not be
     * labelled as WAV, or the file claims a container it does not have and no
     * player can decode it.
     *
     * @see https://docs.x.ai/developers/model-capabilities/audio/text-to-speech
     */
    private const TTS_CODECS = [
        'mp3' => ['mp3', 'audio/mpeg'],
        'wav' => ['wav', 'audio/wav'],
        'pcm' => ['pcm', 'audio/pcm'],
        'mulaw' => ['ulaw', 'audio/basic'],
        'alaw' => ['alaw', 'audio/alaw'],
    ];

    /** xAI's accepted `speed` range. Out-of-range values are rejected with a 400. */
    private const TTS_MIN_SPEED = 0.7;
    private const TTS_MAX_SPEED = 1.5;

    private const TIMEOUT_AUDIO_SECONDS = 120;

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

    private ?\OpenAI\Client $client = null;

    /** Key the cached client was built with (rebuild on key change). */
    private ?string $clientKey = null;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     */
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly ?string $apiKey = null,
        private readonly string $uploadDir = '/var/www/backend/var/uploads',
        // Injectable so unit tests can poll without real sleeps.
        private readonly int $pollIntervalSeconds = self::POLL_INTERVAL_SECONDS,
        private readonly ?ProviderKeyStore $keyStore = null,
        private readonly StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
    ) {
    }

    private function resolveApiKey(): ?string
    {
        if (null !== $this->apiKey && '' !== $this->apiKey) {
            return $this->apiKey;
        }

        return $this->keyStore?->getKey($this->getName());
    }

    /**
     * Lazily build the API client with the CURRENT key; rebuilt when the key
     * changes at runtime (admin UI save / env import).
     */
    private function client(): ?\OpenAI\Client
    {
        $key = $this->resolveApiKey();
        if (null === $key || '' === $key) {
            $this->client = null;
            $this->clientKey = null;

            return null;
        }

        if (null === $this->client || $this->clientKey !== $key) {
            $this->client = \OpenAI::factory()
                ->withApiKey($key)
                ->withBaseUri(self::BASE_URI)
                ->make();
            $this->clientKey = $key;
        }

        return $this->client;
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
        return 'xAI Grok — long-context chat with configurable reasoning and tool calling, image understanding, Grok Imagine image and video generation, plus Grok voice synthesis and transcription.';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'vision', 'image_generation', 'video_generation', 'text_to_speech', 'speech_to_text'];
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
        return null !== $this->client();
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
            $response = $this->client()->chat()->create($requestOptions);
            $responseArray = $response->toArray();

            return $this->mergeChatCompletionsToolResult([
                'content' => $response->choices[0]->message->content ?? '',
                'usage' => $this->parseUsage($responseArray['usage'] ?? []),
            ], $responseArray['choices'][0] ?? []);
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
            $stream = $this->client()->chat()->createStreamed($requestOptions);

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
            $response = $this->client()->chat()->create([
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
            $response = $this->client()->chat()->create([
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

        // Must match the catalog row's `default_resolution`, because
        // CostCalculationService prices the image from that same value.
        $resolution = $this->resolutionFromOptions($options, $modelConfig, self::IMAGE_RESOLUTIONS);
        if (null !== $resolution) {
            $body['resolution'] = $resolution;
        }

        $this->logger->info('xAI: generateImage', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'n' => $body['n'],
            'aspect_ratio' => $aspectRatio,
            'resolution' => $resolution,
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

            if (null !== $progressCallback) {
                // Same payload shape GoogleProvider and HiggsfieldProvider emit;
                // MediaGenerationHandler's callback is typed `array` and reads
                // these keys to render the live progress bar.
                $progressCallback([
                    'status' => $result['status'],
                    'attempt' => $attempt,
                    'max_attempts' => self::POLL_MAX_ATTEMPTS,
                    'elapsed_seconds' => time() - $startedAt,
                    'percent' => $result['percent'],
                    'request_id' => $requestId,
                ]);
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
            'aspect_ratio' => $body['aspect_ratio'] ?? null,
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

    // ==================== TEXT TO SPEECH (Grok voice) ====================

    /**
     * Synthesize `$text` and store the audio under the upload dir.
     *
     * @param array{voice?: string, format?: string, language?: string, speed?: float|string} $options
     *
     * @return string Filename relative to the upload dir
     */
    public function synthesize(string $text, array $options = []): string
    {
        $audio = $this->requestSpeech($text, $options);
        $codec = $this->ttsCodecFromOptions($options);

        $filename = 'tts_'.uniqid().'.'.self::TTS_CODECS[$codec][0];
        $outputPath = $this->uploadDir.'/'.$filename;

        if (!FileHelper::createDirectory($this->uploadDir)) {
            throw new ProviderException('Unable to create upload directory: '.$this->uploadDir, self::PROVIDER_NAME);
        }

        FileHelper::writeFile($outputPath, $audio);

        return $filename;
    }

    /**
     * xAI streams synthesis over a WebSocket only (`wss://api.x.ai/v1/tts`),
     * which Symfony's HTTP client cannot speak. Synthesize unary and replay the
     * result from disk, the same shape GoogleProvider uses, so the TTS endpoint
     * keeps working while `supportsStreaming()` tells callers the audio is not
     * actually incremental.
     *
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, string, void, void>
     */
    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $filename = $this->synthesize($text, $options);
        $fullPath = $this->uploadDir.'/'.$filename;

        $handle = @fopen($fullPath, 'rb');
        if (false === $handle) {
            throw new ProviderException('xAI TTS: cannot read synthesized file '.$fullPath, self::PROVIDER_NAME);
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

    /**
     * @param array<string, mixed> $options
     */
    public function getStreamContentType(array $options = []): string
    {
        return self::TTS_CODECS[$this->ttsCodecFromOptions($options)][1];
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    /**
     * @return array<int, array{id: string, name: string, description: string}>
     */
    public function getVoices(): array
    {
        if (null === $this->resolveApiKey()) {
            return $this->builtinVoices();
        }

        try {
            $data = $this->requestJson('GET', self::TTS_VOICES_ENDPOINT, null, self::TIMEOUT_POLL_SECONDS);
        } catch (\Throwable $e) {
            $this->logger->warning('xAI: could not fetch the voice roster, falling back to the built-in voices', [
                'error' => $e->getMessage(),
            ]);

            return $this->builtinVoices();
        }

        $voices = [];
        foreach (is_array($data['voices'] ?? null) ? $data['voices'] : [] as $voice) {
            if (!is_array($voice)) {
                continue;
            }

            $id = $voice['voice_id'] ?? ($voice['id'] ?? ($voice['name'] ?? null));
            if (!is_string($id) || '' === $id) {
                continue;
            }

            $voices[] = [
                'id' => $id,
                'name' => is_string($voice['name'] ?? null) ? $voice['name'] : $id,
                'description' => is_string($voice['description'] ?? null) ? $voice['description'] : '',
            ];
        }

        return [] !== $voices ? $voices : $this->builtinVoices();
    }

    // ==================== SPEECH TO TEXT (Grok voice) ====================

    /**
     * `keyterm` biases the transcription toward names the model would otherwise
     * mishear (product names, people, jargon).
     *
     * @param array{language?: string, diarize?: bool, keyterm?: array<int, string>} $options
     *
     * @return array{text: string, language: string, duration: float, words: array<int, array<string, mixed>>}
     */
    public function transcribe(string $audioPath, array $options = []): array
    {
        $this->assertApiKey();
        $key = $this->resolveApiKey();

        $fullPath = $this->resolveExistingAudioPath($audioPath);
        $language = is_string($options['language'] ?? null) && '' !== $options['language']
            ? $options['language']
            : null;
        $keyTerms = $this->keyTermsFromOptions($options);

        // Each entry is a single-pair array, the one FormDataPart shape that can
        // emit the same field name twice: xAI expects key terms as a REPEATED
        // `keyterm` field, and the plain map shape would send `keyterm[0]`.
        $fields = [];
        if (null !== $language) {
            $fields[] = ['language' => $language];
        }
        if (true === ($options['diarize'] ?? null)) {
            $fields[] = ['diarize' => 'true'];
        }
        foreach ($keyTerms as $term) {
            $fields[] = ['keyterm' => $term];
        }
        // xAI requires `file` to be the LAST field of the multipart body.
        $fields[] = ['file' => DataPart::fromPath($fullPath)];

        $this->logger->info('xAI: transcribe', [
            'file' => basename($fullPath),
            'language' => $language,
            'keyterms' => count($keyTerms),
        ]);

        try {
            $formData = new FormDataPart($fields);

            $response = $this->httpClient->request('POST', self::STT_ENDPOINT, [
                'auth_bearer' => $key,
                'headers' => $formData->getPreparedHeaders()->toArray(),
                'body' => $formData->bodyToIterable(),
                'timeout' => self::TIMEOUT_AUDIO_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $data = $response->toArray(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('xAI transcription failed: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }

        if ($statusCode >= 400) {
            $this->handleErrorResponse($statusCode, $data);
        }

        return [
            'text' => is_string($data['text'] ?? null) ? $data['text'] : '',
            // xAI documents `language` as "currently empty — detection is not yet
            // enabled" even though live responses do carry it, so prefer the
            // reported value and fall back to what the caller asked for.
            'language' => is_string($data['language'] ?? null) && '' !== $data['language']
                ? $data['language']
                : ($language ?? 'unknown'),
            // Drives per-second billing in AiFacade, so it must be the provider's
            // own measurement rather than anything we estimate locally.
            'duration' => (float) ($data['duration'] ?? 0),
            'words' => is_array($data['words'] ?? null) ? $data['words'] : [],
        ];
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        throw new ProviderException('xAI has no audio translation endpoint. Transcribe with transcribe() and translate the text with a chat model instead.', self::PROVIDER_NAME);
    }

    /**
     * Normalise `keyterm` to what xAI accepts: at most 100 terms of at most 50
     * characters each.
     *
     * Unusable terms are dropped rather than raised as an error. They are hints,
     * not content — losing one costs a few misspelled names, while rejecting the
     * request would throw away a transcription the caller already paid for. The
     * warning names the offenders so the caller can fix them.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function keyTermsFromOptions(array $options): array
    {
        if (!is_array($options['keyterm'] ?? null) || [] === $options['keyterm']) {
            return [];
        }

        $terms = [];
        $dropped = [];
        foreach ($options['keyterm'] as $term) {
            $term = is_string($term) ? trim($term) : '';
            if ('' === $term) {
                continue;
            }

            if (mb_strlen($term) > self::STT_MAX_KEYTERM_LENGTH) {
                $dropped[] = $term;
                continue;
            }

            $terms[] = $term;
        }

        $terms = array_values(array_unique($terms));

        if (count($terms) > self::STT_MAX_KEYTERMS) {
            $dropped = array_merge($dropped, array_slice($terms, self::STT_MAX_KEYTERMS));
            $terms = array_slice($terms, 0, self::STT_MAX_KEYTERMS);
        }

        if ([] !== $dropped) {
            $this->logger->warning('xAI: dropped unusable transcription key terms', [
                'kept' => count($terms),
                'dropped' => $dropped,
                'max_terms' => self::STT_MAX_KEYTERMS,
                'max_length' => self::STT_MAX_KEYTERM_LENGTH,
            ]);
        }

        return $terms;
    }

    // ==================== HELPERS: VOICE ====================

    /**
     * POST the synthesis request and return the raw audio bytes.
     *
     * @param array<string, mixed> $options
     */
    private function requestSpeech(string $text, array $options): string
    {
        $this->assertApiKey();
        $key = $this->resolveApiKey();

        $trimmed = trim($text);
        if ('' === $trimmed) {
            throw new ProviderException('xAI TTS needs a non-empty text.', self::PROVIDER_NAME);
        }

        $length = mb_strlen($trimmed);
        if ($length > self::TTS_MAX_CHARS) {
            throw new ProviderException(sprintf('xAI TTS accepts at most %d characters, got %d. Split the text into smaller chunks.', self::TTS_MAX_CHARS, $length), self::PROVIDER_NAME);
        }

        $codec = $this->ttsCodecFromOptions($options);
        $body = [
            'text' => $trimmed,
            'voice_id' => is_string($options['voice'] ?? null) && '' !== $options['voice']
                ? $options['voice']
                : self::DEFAULT_TTS_VOICE,
            'language' => is_string($options['language'] ?? null) && '' !== $options['language']
                ? $options['language']
                : self::DEFAULT_TTS_LANGUAGE,
            'output_format' => ['codec' => $codec],
        ];

        // TtsController clamps to OpenAI's 0.25-4.0 range, which xAI rejects
        // above 1.5. Clamp instead of failing: the speed is a preference, and a
        // 400 would cost the user the whole synthesis.
        if (is_numeric($options['speed'] ?? null)) {
            $body['speed'] = max(self::TTS_MIN_SPEED, min(self::TTS_MAX_SPEED, (float) $options['speed']));
        }

        $this->logger->info('xAI: synthesize', [
            'voice' => $body['voice_id'],
            'language' => $body['language'],
            'codec' => $codec,
            'characters' => $length,
        ]);

        try {
            $response = $this->httpClient->request('POST', self::TTS_ENDPOINT, [
                'headers' => [
                    'Authorization' => 'Bearer '.$key,
                    'Content-Type' => 'application/json',
                ],
                'json' => $body,
                'timeout' => self::TIMEOUT_AUDIO_SECONDS,
            ]);

            $statusCode = $response->getStatusCode();
            $contentType = $response->getHeaders(false)['content-type'][0] ?? '';
            // Never toArray() here: the success body is binary audio.
            $content = $response->getContent(false);
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('xAI TTS failed: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }

        if ($statusCode >= 400) {
            $decoded = json_decode($content, true);
            $this->handleErrorResponse($statusCode, is_array($decoded) ? $decoded : ['message' => substr($content, 0, 500)]);
        }

        // `/v1/tts` answers with raw audio bytes. It only switches to a JSON
        // envelope carrying base64 audio when `with_timestamps` is requested,
        // which we never do — decode it anyway so a future default flip cannot
        // silently write base64 text into an .mp3 file.
        if (str_contains($contentType, 'application/json')) {
            $content = $this->decodeSpeechEnvelope($content);
        }

        if ('' === $content) {
            throw new ProviderException('xAI TTS returned no audio.', self::PROVIDER_NAME);
        }

        return $content;
    }

    private function decodeSpeechEnvelope(string $content): string
    {
        $payload = json_decode($content, true);
        $base64 = is_array($payload) && is_string($payload['audio'] ?? null) ? $payload['audio'] : '';
        if ('' === $base64) {
            throw new ProviderException('xAI TTS returned a JSON response without audio.', self::PROVIDER_NAME);
        }

        $decoded = base64_decode($base64, true);
        if (false === $decoded) {
            throw new ProviderException('xAI TTS returned invalid base64 audio.', self::PROVIDER_NAME);
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function ttsCodecFromOptions(array $options): string
    {
        $requested = $options['format'] ?? null;
        if (is_string($requested)) {
            $normalized = 'ulaw' === $requested ? 'mulaw' : $requested;
            if (isset(self::TTS_CODECS[$normalized])) {
                return $normalized;
            }
        }

        return self::DEFAULT_TTS_CODEC;
    }

    /**
     * @return array<int, array{id: string, name: string, description: string}>
     */
    private function builtinVoices(): array
    {
        $voices = [];
        foreach (self::TTS_BUILTIN_VOICES as $id => $description) {
            $voices[] = ['id' => $id, 'name' => ucfirst($id), 'description' => $description];
        }

        return $voices;
    }

    private function resolveExistingAudioPath(string $audioPath): string
    {
        $fullPath = str_starts_with($audioPath, '/')
            ? $audioPath
            : $this->uploadDir.'/'.ltrim($audioPath, '/');

        if (!file_exists($fullPath)) {
            throw new ProviderException("Audio file not found: {$fullPath}", self::PROVIDER_NAME);
        }

        return $fullPath;
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
        if (null === $this->client()) {
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
     * Chunks without a delta are normal and must stay silent: `include_usage`
     * makes xAI close every stream with a `choices: []` usage-only chunk. The
     * `??` chains absorb those, so nothing here may dereference blindly.
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

        $this->emitChatCompletionsToolDeltas($responseArray['choices'][0] ?? [], $callback);
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

        $schema = $options['structured_output'] ?? null;
        if ($schema instanceof StructuredOutputSchema) {
            $request = array_merge($request, $this->structuredOutputTranslator->translate($this->getName(), $model, $stream, $schema));
        }

        return $this->applyChatCompletionsToolOptions($request, $options);
    }

    /**
     * Resolve xAI's `reasoning_effort`.
     *
     * Mirrors {@see OpenAIProvider::resolveReasoningConfig()} so "default chat =
     * fast, Thinking toggle = deep" behaves the same across providers.
     *
     * Resolution order:
     *   1. A model that does not accept the parameter → null.
     *   2. Explicit `reasoning_effort` string wins.
     *   3. The Thinking toggle (`reasoning` bool): true → the catalog's
     *      `reasoning_effort_default` (or `high`), false → `none`, which is
     *      cheaper than xAI's server-side default of `low`.
     *   4. No signal, or a model row that does not advertise reasoning → null,
     *      so nothing is sent.
     *
     * @param array<string, mixed> $options
     */
    private function resolveReasoningEffort(string $model, array $options): ?string
    {
        if (!$this->supportsReasoningEffort($model)) {
            return null;
        }

        $explicit = $options['reasoning_effort'] ?? null;
        if (is_string($explicit) && in_array(strtolower($explicit), self::REASONING_EFFORTS, true)) {
            return strtolower($explicit);
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
            return 'none';
        }

        $default = $this->modelConfigFromOptions($options)['reasoning_effort_default'] ?? null;
        if (is_string($default) && in_array($default, self::REASONING_EFFORTS, true)) {
            return $default;
        }

        return 'high';
    }

    private function supportsReasoningEffort(string $model): bool
    {
        foreach (self::REASONING_EFFORT_MODELS as $family) {
            if (str_starts_with($model, $family)) {
                return true;
            }
        }

        return false;
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

        $resolution = $this->resolutionFromOptions($options, $modelConfig, self::VIDEO_RESOLUTIONS);
        if (null !== $resolution) {
            $body['resolution'] = $resolution;
        }

        $imageUrl = $this->videoReferenceImage($options);
        if (null !== $imageUrl) {
            $body['image'] = ['url' => $imageUrl];
        }

        // Only text-to-video gets an aspect ratio. On image-to-video the
        // reference frame defines the geometry and xAI satisfies an explicit
        // aspect_ratio by STRETCHING the still into it — a square 1024x1024
        // input plus the caller's 16:9 default came back as a visibly distorted
        // 1280x720 render. Omitting the field makes xAI keep the frame's shape.
        $aspectRatio = $this->aspectRatioFromOptions($options, $modelConfig);
        if (null !== $aspectRatio && !isset($body['image'])) {
            $body['aspect_ratio'] = $aspectRatio;
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
     * Asking xAI for a resolution the row does not price would render fine but
     * get billed at the row's flat fallback rate, so we never forward an
     * unpriceable value. Two independent constraints decide what is safe:
     *
     * - `allowed_resolutions` (or the API-level fallback list) — what may be
     *   requested at all.
     * - the keys of `resolution_prices` — what has a per-resolution price. A row
     *   without that key bills every resolution at `priceOut`, so nothing needs
     *   restricting there.
     *
     * Both are applied, because either one alone leaves a gap: a row that prices
     * only 480p/720p while omitting `allowed_resolutions` would otherwise fall
     * back to the API list and be allowed to render an unpriced 1080p.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $modelConfig
     * @param array<int, string>   $fallbackAllowed used when the row lists none
     */
    private function resolutionFromOptions(array $options, array $modelConfig, array $fallbackAllowed): ?string
    {
        $allowed = is_array($modelConfig['allowed_resolutions'] ?? null)
            ? array_values(array_filter($modelConfig['allowed_resolutions'], 'is_string'))
            : [];
        $effectiveAllowed = [] !== $allowed ? $allowed : $fallbackAllowed;

        $priced = is_array($modelConfig['resolution_prices'] ?? null)
            ? array_keys($modelConfig['resolution_prices'])
            : [];
        if ([] !== $priced) {
            $effectiveAllowed = array_values(array_intersect($effectiveAllowed, $priced));
        }

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

        if (false === $handle) {
            // The render is already submitted and billable, but without a handle
            // nobody can poll it. Say so here instead of handing out an empty
            // string that only fails later as "invalid operation handle".
            throw new ProviderException(sprintf('Cannot build xAI video operation handle for request %s: %s', $requestId, json_last_error_msg()), self::PROVIDER_NAME);
        }

        return $handle;
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
        // A moderated-away render answers 200 with `respect_moderation: false`
        // and no usable bytes, so say why instead of reporting a broken payload.
        // xAI exposes the flag on the response; tolerate a per-item one too.
        if (false === ($data['respect_moderation'] ?? true)) {
            throw new ProviderException('Content blocked by xAI safety filter', self::PROVIDER_NAME);
        }

        $items = $data['data'] ?? null;
        if (!is_array($items) || [] === $items) {
            $this->logger->error('xAI: image response missing data array', ['keys' => array_keys($data)]);

            throw new ProviderException('xAI returned no images', self::PROVIDER_NAME);
        }

        $images = [];
        $moderated = false;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            if (false === ($item['respect_moderation'] ?? true)) {
                $moderated = true;
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
            throw new ProviderException($moderated ? 'Content blocked by xAI safety filter' : 'xAI returned no usable image payload', self::PROVIDER_NAME);
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
        $key = $this->resolveApiKey();

        $requestOptions = [
            'headers' => [
                'Authorization' => 'Bearer '.$key,
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
