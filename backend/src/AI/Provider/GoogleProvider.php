<?php

namespace App\AI\Provider;

use App\AI\Credential\ProviderKeyStore;
use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ChatProviderInterface;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\SpeechToTextProviderInterface;
use App\AI\Interface\SupportsAsyncVideo;
use App\AI\Interface\TextToSpeechProviderInterface;
use App\AI\Interface\ToolCallingChatProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use App\AI\Interface\VisionProviderInterface;
use App\AI\Provider\Google\GoogleApiClient;
use App\AI\Provider\Google\GoogleChat;
use App\AI\Provider\Google\GoogleImageGeneration;
use App\AI\Provider\Google\GoogleVision;
use App\AI\StructuredOutput\StructuredOutputCapability;
use App\AI\StructuredOutput\StructuredOutputTranslator;
use App\AI\Tool\CatalogToolUse;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Google AI Provider.
 *
 * Supports:
 * - Gemini 2.5 / 3.x Flash and Pro (Chat, Vision, tools)
 * - Gemini native image models (Nano Banana) and optional Vertex Imagen
 * - Veo 3.1 (predictLongRunning) and Gemini Omni Flash (Interactions API)
 * - Gemini TTS and Gemini 3.5 Transcribe
 */
class GoogleProvider implements ChatProviderInterface, ToolCallingChatProviderInterface, ImageGenerationProviderInterface, VideoGenerationProviderInterface, VisionProviderInterface, SpeechToTextProviderInterface, TextToSpeechProviderInterface, SupportsAsyncVideo
{
    /**
     * Last-resort default resolution used when neither the caller nor the
     * model JSON declares one. Mirrors
     * MediaGenerationService::DEFAULT_VIDEO_RESOLUTION_FALLBACK so the
     * product-wide default ("1080p") stays consistent end-to-end.
     */
    private const DEFAULT_VEO_RESOLUTION = '1080p';

    /**
     * Maps our canonical (display-friendly) resolution values to the exact
     * strings Google's Veo API expects in the request payload.
     *
     * We keep "4K" (capital K) internally for nice UI/billing/logging, but
     * Google's `predictLongRunning` endpoint only accepts the lowercase
     * "4k" – any other casing returns HTTP 400 INVALID_ARGUMENT.
     *
     * @see https://ai.google.dev/gemini-api/docs/video#control_the_resolution
     *
     * @var array<string, string>
     */
    private const VEO_API_RESOLUTION_MAP = [
        '4K' => '4k',
    ];

    /**
     * Polling cadence for {@see pollVideoUntilComplete()}. Each attempt sleeps
     * for this many seconds before checking the long-running operation again.
     */
    private const VEO_POLL_INTERVAL_SECONDS = 5;

    /**
     * Maximum total wall-clock time (in seconds) we wait for Veo to finish a
     * generation before giving up. Higher resolutions take noticeably longer
     * (Google explicitly warns that 4K has higher latency), so we tier the
     * timeout to avoid bailing out on healthy long-running 4K jobs while
     * keeping fast 720p/1080p paths from hanging on real failures.
     *
     * @see https://ai.google.dev/gemini-api/docs/video#control_the_resolution
     *
     * @var array<string, int>
     */
    private const VEO_POLL_TIMEOUTS_SECONDS = [
        '720p' => 300,
        '1080p' => 300,
        '4K' => 900,
    ];

    /**
     * Fallback wall-clock budget when the resolution is missing or unknown.
     */
    private const VEO_POLL_TIMEOUT_DEFAULT_SECONDS = 300;

    /**
     * Opaque handle prefix so poll/cancel/download can tell an Omni
     * Interactions id from a Veo long-running operation name.
     */
    private const OMNI_OPERATION_PREFIX = 'interactions/';

    /**
     * Omni clips are 3–10 seconds (https://ai.google.dev/gemini-api/docs/omni).
     */
    private const OMNI_DURATION_MIN_SECONDS = 3;

    private const OMNI_DURATION_MAX_SECONDS = 10;

    private const OMNI_DURATION_DEFAULT_SECONDS = 8;

    /**
     * Official Omni video rate is published for 720p only (~$0.10/sec).
     */
    private const OMNI_DEFAULT_RESOLUTION = '720p';

    /**
     * Gemini Transcribe bills audio at 25 tokens/sec; used to recover duration
     * from usageMetadata when the response has no clock field.
     */
    private const TRANSCRIBE_AUDIO_TOKENS_PER_SECOND = 25;

    private readonly GoogleApiClient $api;
    private readonly GoogleChat $chat;
    private readonly GoogleImageGeneration $images;
    private readonly GoogleVision $vision;

    /**
     * $apiKey is an explicit override (tests, custom wiring) that wins over
     * the ProviderKeyStore. Production wiring passes only the store, so keys
     * saved in the admin UI (or imported from env) apply without a restart.
     * $projectId, $region and $vertexAccessToken stay env-injected — only the
     * Gemini API key is resolved through the store.
     */
    public function __construct(
        private LoggerInterface $logger,
        HttpClientInterface $httpClient,
        ?string $apiKey = null,
        ?string $projectId = null,
        string $region = 'us-central1',
        private string $uploadDir = '/var/www/backend/var/uploads',
        ?string $vertexAccessToken = null,
        ?ProviderKeyStore $keyStore = null,
        StructuredOutputTranslator $structuredOutputTranslator = new StructuredOutputTranslator(new StructuredOutputCapability()),
    ) {
        $this->api = new GoogleApiClient($httpClient, $logger, $apiKey, $keyStore, $projectId, $region, $vertexAccessToken);
        $this->chat = new GoogleChat($this->api, $logger, $structuredOutputTranslator);
        $this->images = new GoogleImageGeneration($this->api, $logger, $uploadDir);
        $this->vision = new GoogleVision($this->api, $logger, $uploadDir);
    }

    public function getName(): string
    {
        return 'google';
    }

    public function supportsToolCalling(string $model): bool
    {
        if (CatalogToolUse::hasChatRow($this->getName(), $model)) {
            return CatalogToolUse::supports($this->getName(), $model);
        }

        return true;
    }

    public function getDisplayName(): string
    {
        return 'Google AI';
    }

    public function getDescription(): string
    {
        return 'Gemini models with multimodal capabilities including video';
    }

    public function getCapabilities(): array
    {
        return ['chat', 'embedding', 'vision', 'image_generation', 'video_generation', 'speech_to_text', 'text_to_speech'];
    }

    public function getDefaultModels(): array
    {
        return [];
    }

    public function getStatus(): array
    {
        if (null === $this->api->key()) {
            return [
                'healthy' => false,
                'error' => 'API key not configured',
            ];
        }

        return [
            'healthy' => true,
            'latency_ms' => 100,
            'error_rate' => 0.0,
            'active_connections' => 0,
        ];
    }

    public function isAvailable(): bool
    {
        return null !== $this->api->key();
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'GOOGLE_GEMINI_API_KEY' => [
                'required' => true,
                'any_of' => ['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_API_KEY'],
                'hint' => 'Get your API key from https://aistudio.google.com/app/apikey',
            ],
        ];
    }

    // ==================== CHAT ====================

    public function chat(array $messages, array $options = []): array
    {
        return $this->chat->chat($messages, $options);
    }

    public function chatStream(array $messages, callable $callback, array $options = []): array
    {
        return $this->chat->chatStream($messages, $callback, $options);
    }

    // ==================== IMAGE GENERATION ====================

    public function generateImage(string $prompt, array $options = []): array
    {
        return $this->images->generateImage($prompt, $options);
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        return $this->images->createVariations($imageUrl, $count);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        return $this->images->editImage($imageUrl, $maskUrl, $prompt);
    }

    // ==================== VIDEO GENERATION ====================

    public function generateVideo(string $prompt, array $options = []): array
    {
        try {
            $operationData = $this->startVideoOperation($prompt, $options);
            $progressCallback = $options['progress_callback'] ?? null;

            // Stop button support (issue #1145): MediaGenerationHandler passes a
            // `cancel_check` probe that turns true when the user aborts the turn
            // (global Stop / per-card Stop / client disconnect). Forward it into
            // the poll loop so we stop waiting AND tell Veo to cancel the
            // operation — otherwise the render finishes server-side and the
            // Google AI credits are burned for a video nobody will see.
            $cancelCheck = isset($options['cancel_check']) && is_callable($options['cancel_check'])
                ? $options['cancel_check']
                : null;

            $videoUri = $this->pollVideoUntilComplete(
                $operationData['operationName'],
                $progressCallback,
                $operationData['resolution'],
                $cancelCheck,
            );

            $videoDataUrl = $this->downloadVideoContent($videoUri);

            return [[
                'url' => $videoDataUrl,
                'revised_prompt' => $prompt,
                'duration' => $operationData['duration'],
                'resolution' => $operationData['resolution'],
            ]];
        } catch (ProviderException $e) {
            throw $e;
        } catch (HttpExceptionInterface $e) {
            $this->logger->error('Google Veo: HTTP Error', [
                'status_code' => $e->getResponse()->getStatusCode(),
                'response_body' => $e->getResponse()->getContent(false),
                'url' => $e->getResponse()->getInfo('url') ?? 'unknown',
            ]);

            throw new ProviderException('Google video generation error: '.$e->getMessage(), 'google');
        } catch (\Exception $e) {
            $this->logger->error('Google Veo: General Error', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw new ProviderException('Google video generation error: '.$e->getMessage(), 'google');
        }
    }

    /**
     * Start a Veo video generation operation without blocking.
     *
     * @return array{operationName: string, model: string, duration: int, resolution: string}
     */
    public function startVideoOperation(string $prompt, array $options = []): array
    {
        $model = $options['model'] ?? 'veo-3.1-generate-preview';

        $key = $this->api->requireKey();

        if ($this->isOmniVideoModel($model)) {
            return $this->startOmniVideoOperation($prompt, $options, $model, $key);
        }

        $requestedDuration = isset($options['duration']) && is_numeric($options['duration'])
            ? (int) $options['duration']
            : 8;
        $durationSeconds = $this->mapToValidVeoDuration($requestedDuration);
        $aspectRatio = $options['aspect_ratio'] ?? '16:9';

        $modelConfig = isset($options['modelConfig']) && is_array($options['modelConfig'])
            ? $options['modelConfig']
            : [];
        $requestedResolution = isset($options['resolution']) && is_string($options['resolution'])
            ? $options['resolution']
            : null;
        $resolution = $this->resolveVeoResolution($requestedResolution, $modelConfig);
        $apiResolution = self::mapResolutionForVeoApi($resolution);

        $this->logger->info('Google Veo: Starting video generation', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'requested_duration' => $requestedDuration,
            'actual_duration' => $durationSeconds,
            'aspect_ratio' => $aspectRatio,
            'requested_resolution' => $requestedResolution,
            'actual_resolution' => $resolution,
            'api_resolution' => $apiResolution,
        ]);

        $url = $this->api->modelUrl($model, 'predictLongRunning');

        $payload = [
            'instances' => [
                ['prompt' => $prompt],
            ],
            'parameters' => [
                'durationSeconds' => $durationSeconds,
                'aspectRatio' => $aspectRatio,
                'resolution' => $apiResolution,
            ],
        ];

        $response = $this->api->postJson($url, $payload, $key, 30);

        $statusCode = $response->getStatusCode();
        if (200 !== $statusCode) {
            $errorBody = $response->getContent(false);
            $this->logger->error('Google Veo: API returned error', [
                'status_code' => $statusCode,
                'error_body' => $errorBody,
                'url' => $url,
            ]);
            throw new ProviderException("Google Veo API error (HTTP $statusCode): $errorBody", 'google');
        }

        $data = $response->toArray();
        $operationName = $data['name'] ?? null;
        if (!$operationName) {
            throw new ProviderException('No operation name returned from Google Veo', 'google');
        }

        $this->logger->info('Google Veo: Operation started', [
            'operation' => $operationName,
        ]);

        return [
            'operationName' => $operationName,
            'model' => $model,
            'duration' => $durationSeconds,
            'resolution' => $resolution,
        ];
    }

    /**
     * Poll a Veo operation once and return its current status.
     *
     * @return array{done: bool, videoUri: ?string, error: ?string}
     */
    public function pollVideoOperationOnce(string $operationName, array $options = []): array
    {
        $key = $this->api->requireKey();

        if ($this->isOmniOperation($operationName)) {
            return $this->pollOmniVideoOperationOnce($operationName, $key);
        }

        $operationUrl = $this->api->url($operationName);

        $statusResponse = $this->api->send('GET', $operationUrl, $key, 30);

        $statusData = $statusResponse->toArray();

        if (!isset($statusData['done']) || true !== $statusData['done']) {
            return ['done' => false, 'videoUri' => null, 'error' => null];
        }

        if (isset($statusData['error'])) {
            $errorMessage = $statusData['error']['message'] ?? 'Unknown error';
            $errorCode = $statusData['error']['code'] ?? 'UNKNOWN';

            $this->logger->error('Google Veo: Operation failed', [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);

            if (str_contains(strtolower($errorMessage), 'safety') || str_contains(strtolower($errorMessage), 'blocked')) {
                throw ProviderException::contentBlocked('google', 'SAFETY', $errorMessage);
            }

            return ['done' => true, 'videoUri' => null, 'error' => $errorMessage.' (code: '.$errorCode.')'];
        }

        // Responsible-AI content filtering: Veo can finish (done:true) WITHOUT an
        // `error` object and WITHOUT any video, signalling the rejection only via
        // `raiMediaFilteredCount`/`raiMediaFilteredReasons` (e.g. "we can't create
        // videos with real people's names or likenesses"). This is a TERMINAL,
        // non-retryable decision — the same prompt will never produce a video — so
        // we surface it as a content block carrying Google's own reason text,
        // rather than letting it fall through to the generic "no video URI" path
        // (which the async advancer would then pointlessly retry as transient).
        $generateVideoResponse = $statusData['response']['generateVideoResponse'] ?? [];
        $filteredReasons = $generateVideoResponse['raiMediaFilteredReasons'] ?? null;
        $filteredCount = $generateVideoResponse['raiMediaFilteredCount'] ?? 0;
        if ((is_array($filteredReasons) && [] !== $filteredReasons) || (is_int($filteredCount) && $filteredCount > 0)) {
            $reasonText = is_array($filteredReasons)
                ? trim(implode(' ', array_filter($filteredReasons, 'is_string')))
                : '';

            $this->logger->warning('Google Veo: media filtered by responsible-AI policy', [
                'filtered_count' => $filteredCount,
                'reasons' => $filteredReasons,
            ]);

            throw ProviderException::contentBlocked('google', 'SAFETY', '' !== $reasonText ? $reasonText : null);
        }

        $videoUri = $statusData['response']['generateVideoResponse']['generatedSamples'][0]['video']['uri']
            ?? $statusData['response']['generatedVideos'][0]['video']['uri']
            ?? null;

        if (!$videoUri) {
            $this->logger->error('Google Veo: No video URI found in response', [
                'response_keys' => array_keys($statusData['response'] ?? []),
                'full_response' => json_encode($statusData, JSON_PRETTY_PRINT),
            ]);

            return ['done' => true, 'videoUri' => null, 'error' => 'No video URI in completed operation response'];
        }

        $this->logger->info('Google Veo: Video generation completed!');

        return ['done' => true, 'videoUri' => $videoUri, 'error' => null];
    }

    /**
     * Download video from a Google-provided URI and return as data URL.
     */
    public function downloadVideoContent(string $videoUri): string
    {
        if (str_starts_with($videoUri, 'data:')) {
            return $videoUri;
        }

        $raw = $this->downloadVideoRaw($videoUri);

        return 'data:video/mp4;base64,'.base64_encode($raw);
    }

    /**
     * Download raw video bytes from a Google-provided URI.
     */
    public function downloadVideoRaw(string $videoUri, array $options = []): string
    {
        if (str_starts_with($videoUri, 'data:')) {
            $comma = strpos($videoUri, ',');
            if (false === $comma) {
                throw new ProviderException('Google Omni: malformed inline video data URI', 'google');
            }
            $decoded = base64_decode(substr($videoUri, $comma + 1), true);
            if (false === $decoded || '' === $decoded) {
                throw new ProviderException('Google Omni: failed to decode inline video', 'google');
            }

            return $decoded;
        }

        $key = $this->api->requireKey();

        $videoResponse = $this->api->send('GET', $videoUri, $key, 120);

        return $videoResponse->getContent();
    }

    /**
     * Poll until the Veo operation completes, calling progressCallback each iteration.
     *
     * The total wait budget is chosen per resolution via
     * {@see self::VEO_POLL_TIMEOUTS_SECONDS} – 4K legitimately takes longer
     * than 720p/1080p, so a single 5-minute ceiling caused false timeouts.
     *
     * @return string The video URI to download
     */
    private function pollVideoUntilComplete(string $operationName, ?callable $progressCallback, ?string $resolution = null, ?callable $cancelCheck = null): string
    {
        $timeoutSeconds = $this->resolvePollTimeoutSeconds($resolution);
        $maxAttempts = (int) ceil($timeoutSeconds / self::VEO_POLL_INTERVAL_SECONDS);
        $startTime = time();

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            // A Veo render legitimately takes minutes (4K longer still); under
            // FrankenPHP the request's max_execution_time is wall-clock and is not
            // disabled by a one-time set_time_limit(0), so the long sleep()/poll
            // loop would be killed mid-render. Re-arm a per-iteration budget
            // (set_time_limit restarts the counter from zero) so it completes.
            $this->extendExecutionTime(self::VEO_POLL_INTERVAL_SECONDS + 30);

            // Honour a cancellation request as early as possible (issue #1145):
            // abort the wait and ask Veo to cancel the operation so we stop
            // burning Google AI credits on an unwanted render.
            if (null !== $cancelCheck && $cancelCheck()) {
                $this->cancelVideoOperation($operationName);

                throw new ProviderCancelledException('Google Veo video generation cancelled', 'google', ['operation' => $operationName]);
            }

            sleep(self::VEO_POLL_INTERVAL_SECONDS);

            $elapsed = time() - $startTime;

            if ($progressCallback) {
                $progressCallback([
                    'status' => 'polling',
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'elapsed_seconds' => $elapsed,
                    'timeout_seconds' => $timeoutSeconds,
                    'resolution' => $resolution,
                ]);
            }

            $this->logger->info('Google Veo: Polling operation', [
                'attempt' => $attempt,
                'max_attempts' => $maxAttempts,
                'elapsed' => $elapsed,
                'timeout_seconds' => $timeoutSeconds,
                'resolution' => $resolution,
            ]);

            $result = $this->pollVideoOperationOnce($operationName);

            if (!$result['done']) {
                continue;
            }

            if ($result['error']) {
                throw new ProviderException('Google video generation failed: '.$result['error'], 'google');
            }

            return $result['videoUri'];
        }

        throw new ProviderException(sprintf('Video generation timed out after %d seconds (resolution: %s)', $timeoutSeconds, $resolution ?? 'unknown'), 'google');
    }

    /**
     * Best-effort cancel of an in-flight Veo operation so Google stops the
     * render (and the billing). The Long-Running-Operations API exposes
     * `POST {operationName}:cancel` (issue #1145). Never throws — by the time
     * we call this we have already decided to walk away from the poll, so a
     * failed cancel must not mask the ProviderCancelledException.
     */
    public function cancelVideoOperation(string $operationName, array $options = []): void
    {
        $key = $this->api->key();
        if (null === $key) {
            return;
        }

        $cancelUrl = $this->isOmniOperation($operationName)
            ? $this->api->url('interactions/'.$this->omniInteractionId($operationName))
            : $this->api->url($operationName.':cancel');
        $method = $this->isOmniOperation($operationName) ? 'DELETE' : 'POST';

        try {
            $response = $this->api->send($method, $cancelUrl, $key, 30);
            $statusCode = $response->getStatusCode();
            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('Google video: cancel request sent to provider', [
                    'operation' => $operationName,
                    'method' => $method,
                ]);
            } else {
                $this->logger->warning('Google video: cancel request failed (already walking away)', [
                    'operation' => $operationName,
                    'status' => $statusCode,
                    'error' => substr($response->getContent(false), 0, 500),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('Google video: cancel request failed (already walking away)', [
                'operation' => $operationName,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Pick the polling wall-clock budget for the given canonical resolution.
     * Falls back to {@see self::VEO_POLL_TIMEOUT_DEFAULT_SECONDS} for unknown
     * or missing values so we never lose the safety net.
     */
    private function resolvePollTimeoutSeconds(?string $resolution): int
    {
        if (null === $resolution || '' === $resolution) {
            return self::VEO_POLL_TIMEOUT_DEFAULT_SECONDS;
        }

        return self::VEO_POLL_TIMEOUTS_SECONDS[$resolution] ?? self::VEO_POLL_TIMEOUT_DEFAULT_SECONDS;
    }

    /**
     * Map requested duration to valid Veo duration values.
     *
     * Google Veo 3.1 only supports 4, 6, or 8 seconds.
     * This method maps any requested duration to the closest valid value.
     */
    private function mapToValidVeoDuration(int|float $requestedDuration): int
    {
        $duration = (int) round($requestedDuration);

        // Veo 3.1 valid values: 4, 6, 8
        if ($duration <= 5) {
            return 4;
        }
        if ($duration <= 7) {
            return 6;
        }

        return 8;
    }

    /**
     * Pick the resolution to send to the Veo API.
     *
     * Honors the request when it is in the model's allowed_resolutions list,
     * otherwise falls back to default_resolution, then to the model's first
     * allowed value, then finally to {@see DEFAULT_VEO_RESOLUTION} (1080p),
     * which is the product-wide default chosen in MediaGenerationService.
     *
     * @param array<string, mixed> $modelConfig
     */
    private function resolveVeoResolution(?string $requested, array $modelConfig): string
    {
        $allowed = is_array($modelConfig['allowed_resolutions'] ?? null)
            ? array_values(array_filter($modelConfig['allowed_resolutions'], 'is_string'))
            : [];

        if (null !== $requested && [] !== $allowed && !in_array($requested, $allowed, true)) {
            $this->logger->warning('Google Veo: Requested resolution not allowed for model, falling back', [
                'requested' => $requested,
                'allowed' => $allowed,
            ]);
            $requested = null;
        }

        if (null !== $requested && '' !== $requested) {
            return $requested;
        }

        $default = $modelConfig['default_resolution'] ?? null;
        if (is_string($default) && '' !== $default) {
            return $default;
        }

        return [] !== $allowed ? $allowed[0] : self::DEFAULT_VEO_RESOLUTION;
    }

    /**
     * Translate our canonical resolution label into the exact string the Veo
     * API accepts in its request payload.
     *
     * Anything not present in {@see self::VEO_API_RESOLUTION_MAP} is returned
     * unchanged, so already-compatible values like "720p"/"1080p" pass
     * through and only "4K" is rewritten to "4k".
     */
    private static function mapResolutionForVeoApi(string $canonical): string
    {
        return self::VEO_API_RESOLUTION_MAP[$canonical] ?? $canonical;
    }

    private function isOmniVideoModel(string $model): bool
    {
        return str_starts_with(strtolower($model), 'gemini-omni');
    }

    private function isOmniOperation(string $operationName): bool
    {
        return str_starts_with($operationName, self::OMNI_OPERATION_PREFIX);
    }

    private function omniInteractionId(string $operationName): string
    {
        return $this->isOmniOperation($operationName)
            ? substr($operationName, strlen(self::OMNI_OPERATION_PREFIX))
            : $operationName;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{operationName: string, model: string, duration: int, resolution: string}
     */
    private function startOmniVideoOperation(string $prompt, array $options, string $model, string $key): array
    {
        $requestedDuration = isset($options['duration']) && is_numeric($options['duration'])
            ? (int) $options['duration']
            : self::OMNI_DURATION_DEFAULT_SECONDS;
        $durationSeconds = $this->mapToValidOmniDuration($requestedDuration);
        $aspectRatio = is_string($options['aspect_ratio'] ?? null) ? $options['aspect_ratio'] : '16:9';
        $modelConfig = isset($options['modelConfig']) && is_array($options['modelConfig'])
            ? $options['modelConfig']
            : [];
        $requestedResolution = isset($options['resolution']) && is_string($options['resolution'])
            ? $options['resolution']
            : null;
        $resolution = $this->resolveOmniResolution($requestedResolution, $modelConfig);

        $this->logger->info('Google Omni: Starting video generation', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'requested_duration' => $requestedDuration,
            'actual_duration' => $durationSeconds,
            'aspect_ratio' => $aspectRatio,
            'resolution' => $resolution,
        ]);

        $url = $this->api->url('interactions');
        $payload = [
            'model' => $model,
            'input' => $prompt,
            'background' => true,
            'store' => true,
            'response_format' => [
                'type' => 'video',
                'aspect_ratio' => $aspectRatio,
                'duration' => $durationSeconds.'s',
                'resolution' => $resolution,
            ],
        ];

        $response = $this->api->postJson($url, $payload, $key, 30);

        $statusCode = $response->getStatusCode();
        if (200 !== $statusCode && 201 !== $statusCode) {
            $errorBody = $response->getContent(false);
            $this->logger->error('Google Omni: API returned error', [
                'status_code' => $statusCode,
                'error_body' => $errorBody,
            ]);
            throw new ProviderException("Google Omni API error (HTTP $statusCode): $errorBody", 'google');
        }

        $data = $response->toArray();
        $interactionId = $data['id'] ?? null;
        if (!is_string($interactionId) || '' === $interactionId) {
            throw new ProviderException('No interaction id returned from Google Omni', 'google');
        }

        $this->logger->info('Google Omni: Interaction started', [
            'interaction' => $interactionId,
            'status' => $data['status'] ?? null,
        ]);

        return [
            'operationName' => self::OMNI_OPERATION_PREFIX.$interactionId,
            'model' => $model,
            'duration' => $durationSeconds,
            'resolution' => $resolution,
        ];
    }

    /**
     * @return array{done: bool, videoUri: ?string, error: ?string}
     */
    private function pollOmniVideoOperationOnce(string $operationName, string $key): array
    {
        $interactionId = $this->omniInteractionId($operationName);
        $statusResponse = $this->api->send('GET', $this->api->url('interactions/'.$interactionId), $key, 60);

        $statusData = $statusResponse->toArray();
        $status = strtolower((string) ($statusData['status'] ?? ''));

        if (in_array($status, ['in_progress', 'queued', 'pending', 'running', ''], true)) {
            return ['done' => false, 'videoUri' => null, 'error' => null];
        }

        if (in_array($status, ['failed', 'cancelled', 'canceled', 'error'], true)) {
            $errorMessage = $this->omniErrorMessage($statusData);

            $this->logger->error('Google Omni: Interaction failed', [
                'status' => $status,
                'error_message' => $errorMessage,
            ]);

            if (str_contains(strtolower($errorMessage), 'safety') || str_contains(strtolower($errorMessage), 'blocked')) {
                throw ProviderException::contentBlocked('google', 'SAFETY', $errorMessage);
            }

            return ['done' => true, 'videoUri' => null, 'error' => $errorMessage];
        }

        $videoUri = $this->extractOmniVideoUri($statusData);
        if (null === $videoUri) {
            $steps = $statusData['steps'] ?? null;
            $this->logger->error('Google Omni: No video in completed interaction', [
                'status' => $status,
                'step_count' => is_array($steps) ? count($steps) : 0,
            ]);

            return ['done' => true, 'videoUri' => null, 'error' => 'No video in completed Omni interaction'];
        }

        $this->logger->info('Google Omni: Video generation completed');

        return ['done' => true, 'videoUri' => $videoUri, 'error' => null];
    }

    /**
     * @param array<string, mixed> $statusData
     */
    private function extractOmniVideoUri(array $statusData): ?string
    {
        $steps = $statusData['steps'] ?? null;
        if (!is_array($steps)) {
            return null;
        }

        $fromModelOutput = $this->extractOmniVideoFromSteps($steps, true);
        if (null !== $fromModelOutput) {
            return $fromModelOutput;
        }

        return $this->extractOmniVideoFromSteps($steps, false);
    }

    /**
     * @param array<mixed> $steps
     */
    private function extractOmniVideoFromSteps(array $steps, bool $modelOutputOnly): ?string
    {
        foreach ($steps as $step) {
            if (!is_array($step)) {
                continue;
            }
            if ($modelOutputOnly && 'model_output' !== ($step['type'] ?? '')) {
                continue;
            }
            $content = $step['content'] ?? null;
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $part) {
                $uri = $this->omniVideoPartToUri($part);
                if (null !== $uri) {
                    return $uri;
                }
            }
        }

        return null;
    }

    private function omniVideoPartToUri(mixed $part): ?string
    {
        if (!is_array($part) || 'video' !== ($part['type'] ?? '')) {
            return null;
        }
        if (isset($part['uri']) && is_string($part['uri']) && '' !== $part['uri']) {
            return $part['uri'];
        }
        $data = $part['data'] ?? null;
        if (!is_string($data) || '' === $data) {
            return null;
        }

        return str_starts_with($data, 'data:') ? $data : 'data:video/mp4;base64,'.$data;
    }

    /**
     * @param array<string, mixed> $statusData
     */
    private function omniErrorMessage(array $statusData): string
    {
        $error = $statusData['error'] ?? null;
        if (is_array($error)) {
            $message = $error['message'] ?? null;

            return is_string($message) && '' !== $message ? $message : 'Omni video generation failed';
        }

        return is_string($error) && '' !== $error ? $error : 'Omni video generation failed';
    }

    private function mapToValidOmniDuration(int $requestedDuration): int
    {
        return max(self::OMNI_DURATION_MIN_SECONDS, min(self::OMNI_DURATION_MAX_SECONDS, $requestedDuration));
    }

    /**
     * @param array<string, mixed> $modelConfig
     */
    private function resolveOmniResolution(?string $requested, array $modelConfig): string
    {
        $allowed = is_array($modelConfig['allowed_resolutions'] ?? null)
            ? array_values(array_filter($modelConfig['allowed_resolutions'], 'is_string'))
            : [self::OMNI_DEFAULT_RESOLUTION];

        if (null !== $requested && in_array($requested, $allowed, true)) {
            return $requested;
        }

        $default = $modelConfig['default_resolution'] ?? null;
        if (is_string($default) && in_array($default, $allowed, true)) {
            return $default;
        }

        return [] !== $allowed ? $allowed[0] : self::OMNI_DEFAULT_RESOLUTION;
    }

    // ==================== VISION ====================

    public function explainImage(string $imageUrl, string $prompt = '', array $options = []): string
    {
        return $this->vision->explainImage($imageUrl, $prompt, $options);
    }

    public function extractTextFromImage(string $imageUrl): string
    {
        return $this->vision->extractTextFromImage($imageUrl);
    }

    public function compareImages(string $imageUrl1, string $imageUrl2): array
    {
        return $this->vision->compareImages($imageUrl1, $imageUrl2);
    }

    public function analyzeImage(string $imagePath, string $prompt, array $options = []): string
    {
        return $this->vision->analyzeImage($imagePath, $prompt, $options);
    }

    // ==================== SPEECH TO TEXT ====================

    public function transcribe(string $audioPath, array $options = []): array
    {
        $key = $this->api->requireKey();

        $model = $options['model'] ?? 'gemini-3.5-transcribe';
        if (!is_string($model) || !$this->isGeminiTranscribeModelId($model)) {
            $model = 'gemini-3.5-transcribe';
        }

        $fullPath = str_starts_with($audioPath, '/')
            ? $audioPath
            : $this->uploadDir.'/'.ltrim($audioPath, '/');

        if (!is_file($fullPath)) {
            throw new ProviderException('Audio file not found: '.$fullPath, 'google');
        }

        $fileSize = filesize($fullPath);
        if (false === $fileSize || $fileSize <= 0) {
            throw new ProviderException('Audio file is empty: '.$fullPath, 'google');
        }
        if ($fileSize > 100 * 1024 * 1024) {
            throw new ProviderException('Audio file too large: '.round($fileSize / 1024 / 1024, 2).'MB (max 100MB)', 'google');
        }

        $audioBytes = file_get_contents($fullPath);
        if (false === $audioBytes || '' === $audioBytes) {
            throw new ProviderException('Failed to read audio file: '.$fullPath, 'google');
        }

        $this->logger->info('Google Gemini: Transcribing audio', [
            'model' => $model,
            'file' => basename($fullPath),
            'size_mb' => round($fileSize / 1024 / 1024, 2),
        ]);

        $url = $this->api->modelUrl($model, 'generateContent');
        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'inlineData' => [
                                'mimeType' => $this->audioMimeType($fullPath),
                                'data' => base64_encode($audioBytes),
                            ],
                        ],
                    ],
                ],
            ],
        ];

        try {
            $response = $this->api->postJson($url, $payload, $key, 180);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $errorBody = $response->getContent(false);
                throw new ProviderException("Google Gemini Transcribe API error (HTTP $statusCode): $errorBody", 'google');
            }

            $data = $response->toArray();
            $text = '';
            foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
                if (isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }

            $duration = $this->transcribeDurationSeconds($data, $fullPath);

            $this->logger->info('Google Gemini: Transcription complete', [
                'model' => $model,
                'duration_seconds' => $duration,
                'text_length' => strlen($text),
            ]);

            return [
                'text' => $text,
                'language' => $options['language'] ?? 'unknown',
                'duration' => $duration,
                'segments' => [],
            ];
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new ProviderException('Google transcription error: '.$e->getMessage(), 'google', null, 0, $e);
        }
    }

    public function translateAudio(string $audioPath, string $targetLang): string
    {
        throw new ProviderException('Google Gemini Transcribe has no dedicated translate mode. Use transcribe() and translate the text with a chat model instead.', 'google');
    }

    private function isGeminiTranscribeModelId(string $model): bool
    {
        return str_starts_with($model, 'gemini-') && str_contains($model, 'transcribe');
    }

    private function audioMimeType(string $path): string
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($ext) {
            'wav' => 'audio/wav',
            'mp3' => 'audio/mpeg',
            'm4a', 'aac' => 'audio/mp4',
            'ogg' => 'audio/ogg',
            'flac' => 'audio/flac',
            'webm' => 'audio/webm',
            default => 'audio/wav',
        };
    }

    /**
     * @param array<string, mixed> $data
     */
    private function transcribeDurationSeconds(array $data, string $fullPath): float
    {
        foreach ($data['usageMetadata']['promptTokensDetails'] ?? [] as $detail) {
            if (!is_array($detail)) {
                continue;
            }
            $modality = strtoupper((string) ($detail['modality'] ?? ''));
            if ('AUDIO' !== $modality) {
                continue;
            }
            $tokens = (int) ($detail['tokenCount'] ?? $detail['tokens'] ?? 0);
            if ($tokens > 0) {
                return $tokens / self::TRANSCRIBE_AUDIO_TOKENS_PER_SECOND;
            }
        }

        $wavSeconds = $this->wavDurationSeconds($fullPath);
        if (null !== $wavSeconds) {
            return $wavSeconds;
        }

        throw new ProviderException(sprintf('Google Gemini Transcribe response omitted audio token usage, and local duration fallback is not implemented for non-WAV format: %s', basename($fullPath)), 'google');
    }

    private function wavDurationSeconds(string $path): ?float
    {
        if (!str_ends_with(strtolower($path), '.wav')) {
            return null;
        }

        $handle = fopen($path, 'rb');
        if (false === $handle) {
            return null;
        }

        try {
            $header = fread($handle, 44);
            if (false === $header || strlen($header) < 44) {
                return null;
            }
            if ('RIFF' !== substr($header, 0, 4) || 'WAVE' !== substr($header, 8, 4)) {
                return null;
            }
            $byteRateParts = unpack('V', substr($header, 28, 4));
            $dataSizeParts = unpack('V', substr($header, 40, 4));
            if (!is_array($byteRateParts) || !is_array($dataSizeParts)) {
                return null;
            }
            $byteRate = (int) ($byteRateParts[1] ?? 0);
            $dataSize = (int) ($dataSizeParts[1] ?? 0);
            if ($byteRate <= 0 || $dataSize <= 0) {
                return null;
            }

            return $dataSize / $byteRate;
        } finally {
            fclose($handle);
        }
    }

    // ==================== TEXT TO SPEECH ====================

    public function synthesize(string $text, array $options = []): string
    {
        $key = $this->api->requireKey();

        try {
            // Accept any Gemini TTS variant Google ships so newer catalog rows
            // (e.g. gemini-3.1-flash-tts-preview) reach the API instead of
            // being silently downgraded to the 2.5 fallback. We still gate on
            // the "gemini-...-tts*" shape so a typo or non-TTS model can never
            // reach :generateContent with audio modality enabled.
            $defaultTtsModel = 'gemini-2.5-flash-preview-tts';
            $model = $options['model'] ?? $defaultTtsModel;
            if (!is_string($model) || !$this->isGeminiTtsModelId($model)) {
                $model = $defaultTtsModel;
            }
            $voiceName = $options['voice'] ?? 'Kore';

            $this->logger->info('Google Gemini: Synthesizing speech with multimodal output', [
                'model' => $model,
                'text_length' => strlen($text),
                'voice' => $voiceName,
            ]);

            // Use Gemini 2.0 Flash with TTS capability (audio output modality)
            $url = $this->api->modelUrl($model, 'generateContent');

            // Gemini 2.0 Flash TTS via speech modality
            $payload = [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            [
                                'text' => $text,
                            ],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseModalities' => ['AUDIO'], // Request audio output
                    'speechConfig' => [
                        'voiceConfig' => [
                            'prebuiltVoiceConfig' => [
                                'voiceName' => $voiceName,
                            ],
                        ],
                    ],
                ],
            ];

            $response = $this->api->postJson($url, $payload, $key, 240, ['max_duration' => 300]);

            $statusCode = $response->getStatusCode();
            if (200 !== $statusCode) {
                $errorBody = $response->getContent(false);
                $this->logger->error('Google Gemini TTS: API error', [
                    'status_code' => $statusCode,
                    'error_body' => $errorBody,
                ]);
                throw new \Exception("Google Gemini TTS API error (HTTP $statusCode): $errorBody");
            }

            $data = $response->toArray();

            $parts = $data['candidates'][0]['content']['parts'] ?? [];
            $audioPart = null;
            foreach ($parts as $part) {
                if (isset($part['inline_data']['data'])) {
                    $this->logger->info('Google Gemini TTS: Found inline_data audio part', [
                        'keys' => array_keys($part['inline_data']),
                        'mime_type' => $part['inline_data']['mime_type'] ?? null,
                        'data_preview' => substr($part['inline_data']['data'], 0, 32),
                    ]);
                    $audioPart = $part['inline_data'];
                    break;
                }
                if (isset($part['inlineData']['data'])) {
                    $this->logger->info('Google Gemini TTS: Found inlineData audio part', [
                        'keys' => array_keys($part['inlineData']),
                        'mime_type' => $part['inlineData']['mimeType'] ?? null,
                        'data_preview' => substr($part['inlineData']['data'], 0, 32),
                    ]);
                    $audioPart = $part['inlineData'];
                    break;
                }
            }

            if (!$audioPart || empty($audioPart['data'])) {
                $this->logger->error('Google Gemini TTS: No audio data in response', [
                    'response' => json_encode($data),
                ]);
                throw new \Exception('No audio data returned from Gemini TTS');
            }

            $base64Audio = $audioPart['data'];
            $mimeType = strtolower($audioPart['mime_type'] ?? $audioPart['mimeType'] ?? 'audio/wav');

            // Decode base64 audio
            $audioData = base64_decode($base64Audio);

            if (false === $audioData || '' === $audioData) {
                throw new \Exception('Failed to decode audio data returned from Gemini TTS');
            }

            // Gemini TTS returns signed 16-bit PCM data by default (see docs)
            // Convert PCM payload to a proper WAV container so browsers can play it.
            // The mime carries the actual format, e.g. "audio/L16;rate=24000;channels=1"
            // (RFC 2586) — parse the rate/channels rather than assuming defaults.
            if ($this->isRawPcmMimeType($mimeType)) {
                [$sampleRate, $channels] = $this->parsePcmParams($mimeType);
                $audioData = $this->convertPcmToWav($audioData, $sampleRate, $channels);
                $mimeType = 'audio/wav';
            }

            // Determine file extension from mime type (after PCM conversion)
            $extension = match (true) {
                str_contains($mimeType, 'wav') => 'wav',
                str_contains($mimeType, 'mp3') => 'mp3',
                str_contains($mimeType, 'ogg') => 'ogg',
                default => 'wav',
            };

            // Save to file with proper permissions
            $this->ensureUploadDirectory();
            $filename = 'tts_'.uniqid().'.'.$extension;
            $outputPath = rtrim($this->uploadDir, '/\\').'/'.$filename;

            $written = FileHelper::writeFile($outputPath, $audioData);
            if (false === $written) {
                throw new \Exception("Failed to write audio file to {$outputPath}");
            }

            $this->logger->info('Google Gemini TTS: Audio saved', [
                'filename' => $filename,
                'size_bytes' => strlen($audioData),
                'mime_type' => $mimeType,
            ]);

            return $filename;
        } catch (ProviderException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new ProviderException('Google TTS error: '.$e->getMessage(), 'google');
        }
    }

    private function ensureUploadDirectory(): void
    {
        if (!FileHelper::createDirectory($this->uploadDir)) {
            throw new \RuntimeException(sprintf('Unable to create upload directory: %s', $this->uploadDir));
        }

        if (!is_writable($this->uploadDir)) {
            throw new \RuntimeException(sprintf('Upload directory is not writable: %s', $this->uploadDir));
        }
    }

    public function synthesizeStream(string $text, array $options = []): \Generator
    {
        $filename = $this->synthesize($text, $options);
        $fullPath = $this->uploadDir.'/'.$filename;

        if (!file_exists($fullPath)) {
            throw new ProviderException('Google TTS: synthesized file not found', 'google');
        }

        $handle = fopen($fullPath, 'rb');
        if (!$handle) {
            throw new ProviderException('Google TTS: cannot read synthesized file', 'google');
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

    public function getStreamContentType(array $options = []): string
    {
        return 'audio/wav';
    }

    public function supportsStreaming(): bool
    {
        return false;
    }

    public function getVoices(): array
    {
        // Google Cloud TTS voices would be loaded here
        return [];
    }

    private function isRawPcmMimeType(?string $mimeType): bool
    {
        if (!$mimeType) {
            return true;
        }

        return str_contains($mimeType, 'pcm')
            || str_contains($mimeType, 'x-raw')
            || str_contains($mimeType, 'linear16')
            // RFC 2586: "audio/L16" is headerless linear 16-bit PCM — what Gemini
            // TTS actually returns ("audio/l16;rate=24000;channels=1"). Without
            // this, the raw bytes were saved as .wav with no RIFF header and no
            // browser could decode them ("audio not available").
            || str_contains($mimeType, 'l16');
    }

    /**
     * Parse sample rate + channel count from a PCM mime type such as
     * "audio/l16;rate=24000;channels=1" (RFC 2586). Falls back to the Gemini
     * defaults (24 kHz mono) when a parameter is absent.
     *
     * @return array{0: int, 1: int} [sampleRate, channels]
     */
    private function parsePcmParams(string $mimeType): array
    {
        $sampleRate = 24000;
        $channels = 1;

        if (preg_match('/rate=(\d+)/', $mimeType, $m)) {
            $sampleRate = max(8000, min(192000, (int) $m[1]));
        }
        if (preg_match('/channels=(\d+)/', $mimeType, $m)) {
            $channels = max(1, min(8, (int) $m[1]));
        }

        return [$sampleRate, $channels];
    }

    /**
     * Recognise the Gemini TTS model family so the catalog can ship new
     * preview ids (gemini-2.5-flash-preview-tts, gemini-2.5-pro-preview-tts,
     * gemini-3.1-flash-tts-preview, …) without a code change. We keep the
     * shape strict enough that non-TTS Gemini models still get rejected.
     */
    private function isGeminiTtsModelId(string $model): bool
    {
        if (!str_starts_with($model, 'gemini-')) {
            return false;
        }

        return str_contains($model, '-tts');
    }

    /**
     * Gemini TTS returns signed 16-bit PCM buffers (24 kHz, mono) by default.
     * Wrap the PCM payload into a RIFF/WAVE container so browsers can play it.
     */
    private function convertPcmToWav(
        string $pcmData,
        int $sampleRate = 24000,
        int $channels = 1,
        int $bitsPerSample = 16,
    ): string {
        $dataSize = strlen($pcmData);
        $blockAlign = (int) ($channels * ($bitsPerSample / 8));
        $byteRate = $sampleRate * $blockAlign;

        $header = 'RIFF'
            .pack('V', 36 + $dataSize)
            .'WAVEfmt '
            .pack('V', 16)
            .pack('v', 1) // PCM format
            .pack('v', $channels)
            .pack('V', $sampleRate)
            .pack('V', $byteRate)
            .pack('v', $blockAlign)
            .pack('v', $bitsPerSample)
            .'data'
            .pack('V', $dataSize);

        return $header.$pcmData;
    }

    /**
     * Re-arm the PHP execution-time limit for one more poll cycle.
     *
     * A Veo render can run for minutes; under FrankenPHP the request's
     * max_execution_time is wall-clock and a one-time set_time_limit(0) does not
     * disable it, so the poll loop restarts the timer each iteration
     * (set_time_limit() resets the counter to zero) to avoid a mid-render
     * "Maximum execution time exceeded". Guarded because the function can be
     * disabled via disable_functions.
     */
    private function extendExecutionTime(int $seconds): void
    {
        if (\function_exists('set_time_limit')) {
            set_time_limit(max(30, $seconds));
        }
    }
}
