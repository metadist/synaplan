<?php

declare(strict_types=1);

namespace App\AI\Provider;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use App\AI\Interface\ImageGenerationProviderInterface;
use App\AI\Interface\VideoGenerationProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Higgsfield AI Provider — Image and Video generation.
 *
 * Wraps the official Higgsfield REST API at platform.higgsfield.ai. Every
 * generation is asynchronous: submit → poll → fetch URL. The provider exposes
 * a synchronous facade (submit + block-until-complete + return URL) so it
 * plugs into the existing ImageGenerationProviderInterface / VideoGeneration
 * ProviderInterface contract used by AiFacade and MediaGenerationHandler.
 *
 * Authentication:
 *   The Higgsfield API uses a key+secret pair: `Authorization: Key {key}:{secret}`.
 *   Credentials are resolved at the {@see AiFacade} layer (per-user override on
 *   top of a platform-wide env default) and passed in via $options['credentials'].
 *   The constructor's $platformApiKey/$platformApiSecret are only used when the
 *   caller did not pre-resolve credentials (e.g. health-check, isAvailable()).
 *
 * Supported model IDs (key-auth tier):
 *   - higgsfield-ai/soul/standard                     — text-to-image
 *   - bytedance/seedream/v4/text-to-image             — text-to-image
 *   - higgsfield-ai/dop/standard                      — image-to-video (DoP Standard)
 *   - kling-video/v2.1/pro/image-to-video             — image-to-video (Kling 2.1 Pro)
 *   - bytedance/seedance/v1/pro/image-to-video        — image-to-video (Seedance v1 Pro)
 *
 * @see https://docs.higgsfield.ai/
 */
final class HiggsfieldProvider implements ImageGenerationProviderInterface, VideoGenerationProviderInterface
{
    private const PROVIDER_NAME = 'higgsfield';
    private const DISPLAY_NAME = 'Higgsfield';

    private const BASE_URL = 'https://platform.higgsfield.ai';

    private const DEFAULT_TEXT_TO_IMAGE_MODEL = 'higgsfield-ai/soul/standard';
    private const DEFAULT_IMAGE_TO_VIDEO_MODEL = 'higgsfield-ai/dop/standard';

    private const TIMEOUT_SUBMIT_SECONDS = 30;
    private const TIMEOUT_POLL_SECONDS = 15;

    /** Seconds between status polls. */
    private const POLL_INTERVAL_SECONDS = 3;

    /** Cap on poll attempts before we give up. 3s × 240 = 12 min. */
    private const POLL_MAX_ATTEMPTS_VIDEO = 240;
    private const POLL_MAX_ATTEMPTS_IMAGE = 60; // images are typically seconds

    /** Log every Nth poll attempt to keep logs readable for long videos. */
    private const POLL_LOG_EVERY = 5;

    /**
     * Higgsfield video models render fixed-length clips. The DoP family is 5s
     * only; Kling supports 5s or 10s. The platform rejects unsupported values
     * (e.g. the generic 8s default that flows down from the media handler), so a
     * requested length is always normalised to a supported one — keeping clips
     * short, cheap, and inside the render-time budget instead of being rejected.
     */
    private const DEFAULT_VIDEO_DURATION = 5;
    private const DEFAULT_VIDEO_DURATIONS = [5, 10];
    private const DOP_VIDEO_DURATIONS = [5];

    /**
     * Rough render budget (seconds) used only to turn elapsed poll time into a
     * monotonic progress percentage for the UI. Higgsfield reports a coarse
     * textual status (queued/in_progress/completed) with no numeric progress, so
     * this drives a smoothly advancing bar that is capped below 100% until the
     * real "completed" status arrives.
     */
    private const ESTIMATED_VIDEO_SECONDS = 90;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $platformApiKey = '',
        private readonly string $platformApiSecret = '',
        // Injectable so unit tests can poll without real sleeps. Production
        // keeps the 3s default; the value only affects the wait between status
        // polls while the worker blocks on the synchronous submit→poll loop
        // (there is no SSE stream here — the worker sleeps between GET polls).
        private readonly int $pollIntervalSeconds = self::POLL_INTERVAL_SECONDS,
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
        return 'Higgsfield AI — cinematic image and video generation (Soul, DoP, Kling, Seedance, Seedream) via the official platform.higgsfield.ai REST API.';
    }

    public function getCapabilities(): array
    {
        return ['image_generation', 'video_generation'];
    }

    public function getDefaultModels(): array
    {
        return [
            'image_generation' => self::DEFAULT_TEXT_TO_IMAGE_MODEL,
            'video_generation' => self::DEFAULT_IMAGE_TO_VIDEO_MODEL,
        ];
    }

    public function getStatus(): array
    {
        if (!$this->hasPlatformCredentials()) {
            return [
                'healthy' => false,
                'error' => 'API key and secret not configured (platform-wide). Per-user keys may still be available.',
            ];
        }

        // We intentionally do NOT fabricate latency/error-rate/connection
        // metrics here: the provider keeps no such counters, so reporting
        // hard-coded zeros would be misleading on a health dashboard. Only
        // report what we can actually attest to — that credentials exist.
        return [
            'healthy' => true,
        ];
    }

    public function isAvailable(): bool
    {
        // We report "available" when EITHER a platform credential is present
        // OR per-user credentials exist for at least one user. Since
        // ProviderRegistry calls this without a user context, we can only
        // see the platform half here; per-user overrides are honored later by
        // AiFacade when it resolves credentials via HiggsfieldCredentialResolver.
        // Returning true when no platform key is set would surface the
        // provider to users who don't have a per-user key either, which is
        // worse than silently hiding it.
        return $this->hasPlatformCredentials();
    }

    public function getRequiredEnvVars(): array
    {
        return [
            'HIGGSFIELD_API_KEY' => [
                'required' => true,
                'hint' => 'Get your API key+secret pair at https://cloud.higgsfield.ai/ → Settings → API',
            ],
            'HIGGSFIELD_API_SECRET' => [
                'required' => true,
                'hint' => 'Paired with HIGGSFIELD_API_KEY — both halves required.',
            ],
        ];
    }

    // ==================== IMAGE GENERATION ====================

    /**
     * @param array{
     *   model?: string,
     *   credentials?: array{api_key: string, api_secret: string},
     *   aspect_ratio?: string,
     *   resolution?: string,
     *   size?: string,
     *   width?: int,
     *   height?: int,
     *   images?: array<int, string>,
     *   modelConfig?: array<string, mixed>,
     * } $options
     *
     * @return array<int, array{url: string|null, b64_json: null, revised_prompt: string|null}>
     */
    public function generateImage(string $prompt, array $options = []): array
    {
        $credentials = $this->credentialsFromOptions($options);
        $model = $this->modelFromOptions($options, self::DEFAULT_TEXT_TO_IMAGE_MODEL);

        $body = [
            'prompt' => $prompt,
        ];

        $aspectRatio = $this->aspectRatioFromOptions($options);
        if (null !== $aspectRatio) {
            $body['aspect_ratio'] = $aspectRatio;
        }

        $resolution = $this->resolutionFromOptions($options);
        if (null !== $resolution) {
            $body['resolution'] = $resolution;
        }

        // Reference images (for image-to-image / pic2pic flows). The API uses
        // image_url for the first reference; additional images are merged into
        // an "input_images" array when the underlying model supports it.
        $referenceImages = $options['images'] ?? [];
        if ([] !== $referenceImages) {
            $first = (string) reset($referenceImages);
            if ('' !== $first) {
                $body['image_url'] = $first;
            }
            if (count($referenceImages) > 1) {
                $body['input_images'] = array_map(
                    static fn ($url): array => ['type' => 'image_url', 'image_url' => (string) $url],
                    $referenceImages,
                );
            }
        }

        $this->logger->info('Higgsfield: generateImage', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'aspect_ratio' => $body['aspect_ratio'] ?? null,
            'resolution' => $body['resolution'] ?? null,
            'has_reference' => isset($body['image_url']),
            'credentials_source' => $credentials['source'],
        ]);

        $submission = $this->submit($model, $body, $credentials);
        $finalPayload = $this->pollUntilTerminal(
            $submission['status_url'],
            $submission['request_id'],
            $credentials,
            self::POLL_MAX_ATTEMPTS_IMAGE,
            'image',
        );

        return $this->parseImagePayload($finalPayload);
    }

    public function createVariations(string $imageUrl, int $count = 1): array
    {
        throw new ProviderException('Higgsfield does not support image variations via the REST API.', self::PROVIDER_NAME);
    }

    public function editImage(string $imageUrl, string $maskUrl, string $prompt): string
    {
        throw new ProviderException('Higgsfield does not support mask-based image editing via the REST API.', self::PROVIDER_NAME);
    }

    // ==================== VIDEO GENERATION ====================

    /**
     * @param array{
     *   model?: string,
     *   credentials?: array{api_key: string, api_secret: string},
     *   image_url?: string,
     *   images?: array<int, string>,
     *   duration?: int,
     *   aspect_ratio?: string,
     *   resolution?: string,
     *   progress_callback?: callable,
     *   cancel_check?: callable,
     *   modelConfig?: array<string, mixed>,
     * } $options
     *
     * @return array<int, array{url: string, duration: int|null, resolution: string|null}>
     */
    public function generateVideo(string $prompt, array $options = []): array
    {
        $credentials = $this->credentialsFromOptions($options);
        $model = $this->modelFromOptions($options, self::DEFAULT_IMAGE_TO_VIDEO_MODEL);

        $body = [
            'prompt' => $prompt,
        ];

        // Most Higgsfield video models are image-to-video; surface the first
        // attached reference image (if any) as image_url. Pure text-to-video
        // models simply ignore it.
        $imageUrl = $options['image_url'] ?? null;
        if (null === $imageUrl) {
            $referenceImages = $options['images'] ?? [];
            if ([] !== $referenceImages) {
                $imageUrl = (string) reset($referenceImages);
            }
        }
        if (is_string($imageUrl) && '' !== $imageUrl) {
            $body['image_url'] = $imageUrl;
        }

        $body['duration'] = $this->durationFromOptions($model, $options);

        $aspectRatio = $this->aspectRatioFromOptions($options);
        if (null !== $aspectRatio) {
            $body['aspect_ratio'] = $aspectRatio;
        }

        $resolution = $this->resolutionFromOptions($options);
        if (null !== $resolution) {
            $body['resolution'] = $resolution;
        }

        $this->logger->info('Higgsfield: generateVideo', [
            'model' => $model,
            'prompt_length' => strlen($prompt),
            'duration' => $body['duration'],
            'aspect_ratio' => $body['aspect_ratio'] ?? null,
            'resolution' => $body['resolution'] ?? null,
            'has_reference' => isset($body['image_url']),
            'credentials_source' => $credentials['source'],
        ]);

        $progressCallback = $options['progress_callback'] ?? null;
        if (!is_callable($progressCallback)) {
            $progressCallback = null;
        }

        // The caller (MediaGenerationHandler) supplies a cancel probe wired to
        // the Stop button / client disconnect. When it trips mid-poll we ask
        // Higgsfield to cancel the request (stops billing) and abort.
        $cancelCheck = $options['cancel_check'] ?? null;
        if (!is_callable($cancelCheck)) {
            $cancelCheck = null;
        }

        $submission = $this->submit($model, $body, $credentials);
        $finalPayload = $this->pollUntilTerminal(
            $submission['status_url'],
            $submission['request_id'],
            $credentials,
            self::POLL_MAX_ATTEMPTS_VIDEO,
            'video',
            $progressCallback,
            $submission['cancel_url'],
            $cancelCheck,
        );

        return $this->parseVideoPayload($finalPayload, $body);
    }

    // ==================== HTTP MECHANICS ====================

    /**
     * Submit a generation request and return the queue URLs to poll.
     *
     * @param array{api_key: string, api_secret: string, source?: string} $credentials
     *
     * @return array{request_id: string, status_url: string, cancel_url: ?string}
     */
    private function submit(string $model, array $body, array $credentials): array
    {
        $endpoint = $this->modelEndpoint($model);

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'headers' => $this->buildAuthHeaders($credentials),
                'json' => $body,
                'timeout' => self::TIMEOUT_SUBMIT_SECONDS,
            ]);

            $status = $response->getStatusCode();
            $data = $response->toArray(false);

            if ($status >= 400) {
                $this->handleErrorResponse($status, $data);
            }
        } catch (HttpExceptionInterface $e) {
            throw new ProviderException('Higgsfield submit failed: '.$e->getMessage(), self::PROVIDER_NAME, null, 0, $e);
        }

        $requestId = $data['request_id'] ?? null;
        $statusUrl = $data['status_url'] ?? null;

        if (!is_string($requestId) || '' === $requestId
            || !is_string($statusUrl) || '' === $statusUrl) {
            $this->logger->error('Higgsfield: submit response missing request_id/status_url', [
                'keys' => array_keys($data),
                'response' => $data,
            ]);
            throw new ProviderException('Higgsfield submit response missing request_id or status_url', self::PROVIDER_NAME);
        }

        return [
            'request_id' => $requestId,
            'status_url' => $statusUrl,
            'cancel_url' => isset($data['cancel_url']) && is_string($data['cancel_url']) ? $data['cancel_url'] : null,
        ];
    }

    /**
     * Poll the queue until the request hits a terminal state, then return the
     * final payload (which contains the generated media URLs).
     *
     * @param array{api_key: string, api_secret: string, source?: string} $credentials
     * @param 'image'|'video'                                             $mediaType
     */
    private function pollUntilTerminal(
        string $statusUrl,
        string $requestId,
        array $credentials,
        int $maxAttempts,
        string $mediaType,
        ?callable $progressCallback = null,
        ?string $cancelUrl = null,
        ?callable $cancelCheck = null,
    ): array {
        $startedAt = time();

        for ($attempt = 1; $attempt <= $maxAttempts; ++$attempt) {
            // A video render legitimately takes minutes, but under the FrankenPHP
            // runtime the request's max_execution_time is wall-clock and is NOT
            // disabled by a single set_time_limit(0) at the start of the stream —
            // so the long sleep()/poll loop below was killed mid-render with
            // "Maximum execution time of 0 seconds exceeded", leaving the task
            // card frozen. Re-arm a generous per-iteration budget (set_time_limit
            // restarts the counter from zero) so a healthy render runs to the end.
            $this->extendExecutionTime($this->pollIntervalSeconds + self::TIMEOUT_POLL_SECONDS + 30);

            // Honour a cancellation request as early as possible so we stop
            // billing instead of finishing a generation nobody is waiting for.
            if (null !== $cancelCheck && $cancelCheck()) {
                $this->cancelRemote($cancelUrl, $credentials, $requestId);

                throw new ProviderCancelledException(sprintf('Higgsfield %s generation cancelled', $mediaType), self::PROVIDER_NAME, ['request_id' => $requestId]);
            }

            if ($this->pollIntervalSeconds > 0) {
                sleep($this->pollIntervalSeconds);
            }

            try {
                $response = $this->httpClient->request('GET', $statusUrl, [
                    'headers' => $this->buildAuthHeaders($credentials),
                    'timeout' => self::TIMEOUT_POLL_SECONDS,
                ]);

                $statusCode = $response->getStatusCode();
                $data = $response->toArray(false);
            } catch (HttpExceptionInterface $e) {
                throw new ProviderException('Higgsfield poll failed: '.$e->getMessage(), self::PROVIDER_NAME, ['request_id' => $requestId], 0, $e);
            }

            if ($statusCode >= 400) {
                $this->handleErrorResponse($statusCode, $data);
            }

            $status = (string) ($data['status'] ?? 'unknown');

            if (null !== $progressCallback) {
                $elapsed = time() - $startedAt;
                $progressCallback([
                    'status' => $status,
                    'attempt' => $attempt,
                    'max_attempts' => $maxAttempts,
                    'elapsed_seconds' => $elapsed,
                    'percent' => $this->estimatePercent($status, $elapsed),
                    'request_id' => $requestId,
                ]);
            }

            if (0 === $attempt % self::POLL_LOG_EVERY) {
                $this->logger->debug('Higgsfield: poll status', [
                    'request_id' => $requestId,
                    'status' => $status,
                    'attempt' => $attempt,
                ]);
            }

            if ('completed' === $status) {
                $this->logger->info('Higgsfield: generation completed', [
                    'request_id' => $requestId,
                    'attempts' => $attempt,
                    'elapsed_seconds' => time() - $startedAt,
                ]);

                return $data;
            }

            if ('failed' === $status) {
                $errorMessage = (string) ($data['error'] ?? 'Higgsfield generation failed');
                throw new ProviderException("Higgsfield {$mediaType} generation failed: {$errorMessage}", self::PROVIDER_NAME, ['request_id' => $requestId, 'response' => $data]);
            }

            if ('nsfw' === $status) {
                throw ProviderException::contentBlocked(self::PROVIDER_NAME, 'NSFW', null);
            }

            if ('cancelled' === $status) {
                throw new ProviderException('Higgsfield generation was cancelled', self::PROVIDER_NAME, ['request_id' => $requestId]);
            }
        }

        throw new ProviderException(sprintf('Higgsfield %s generation timed out after %d seconds', $mediaType, $this->pollIntervalSeconds * $maxAttempts), self::PROVIDER_NAME, ['request_id' => $requestId]);
    }

    /**
     * Best-effort cancel of an in-flight request so Higgsfield stops the render
     * (and the billing). Never throws — cancellation is a courtesy on top of us
     * already having walked away from the poll.
     *
     * @param array{api_key: string, api_secret: string, source?: string} $credentials
     */
    private function cancelRemote(?string $cancelUrl, array $credentials, string $requestId): void
    {
        if (null === $cancelUrl || '' === $cancelUrl) {
            $this->logger->info('Higgsfield: cancellation requested but provider gave no cancel_url', [
                'request_id' => $requestId,
            ]);

            return;
        }

        try {
            $this->httpClient->request('POST', $cancelUrl, [
                'headers' => $this->buildAuthHeaders($credentials),
                'timeout' => self::TIMEOUT_POLL_SECONDS,
            ]);
            $this->logger->info('Higgsfield: cancel request sent to provider', ['request_id' => $requestId]);
        } catch (\Throwable $e) {
            $this->logger->warning('Higgsfield: cancel request failed (already walking away)', [
                'request_id' => $requestId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map a coarse provider status + elapsed time onto a 1-100 progress percent
     * for the UI. Stays below 100% until the provider reports "completed" so the
     * bar never claims done before the file exists.
     */
    private function estimatePercent(string $status, int $elapsed): int
    {
        if ('completed' === $status) {
            return 100;
        }

        $pct = (int) floor(($elapsed / self::ESTIMATED_VIDEO_SECONDS) * 100);

        return max(1, min(95, $pct));
    }

    /**
     * @return array<int, array{url: string|null, b64_json: null, revised_prompt: string|null}>
     */
    private function parseImagePayload(array $data): array
    {
        $images = $data['images'] ?? [];
        if (!is_array($images) || [] === $images) {
            $this->logger->error('Higgsfield: completed response missing images array', [
                'keys' => array_keys($data),
                'payload' => $data,
            ]);
            throw new ProviderException('Higgsfield returned no images', self::PROVIDER_NAME);
        }

        $out = [];
        foreach ($images as $image) {
            $url = is_array($image) ? ($image['url'] ?? null) : null;
            if (!is_string($url) || '' === $url) {
                continue;
            }
            $out[] = [
                'url' => $url,
                'b64_json' => null,
                'revised_prompt' => isset($image['revised_prompt']) && is_string($image['revised_prompt'])
                    ? $image['revised_prompt']
                    : null,
            ];
        }

        if ([] === $out) {
            throw new ProviderException('Higgsfield image response missing URLs', self::PROVIDER_NAME);
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $requestBody used for fallback duration/resolution metadata
     *
     * @return array<int, array{url: string, duration: int|null, resolution: string|null}>
     */
    private function parseVideoPayload(array $data, array $requestBody): array
    {
        $video = $data['video'] ?? null;
        $url = is_array($video) ? ($video['url'] ?? null) : null;

        // Some single-shot endpoints (no queue) return `video_url` directly.
        if (!is_string($url) || '' === $url) {
            $url = isset($data['video_url']) && is_string($data['video_url']) ? $data['video_url'] : null;
        }

        if (!is_string($url) || '' === $url) {
            $this->logger->error('Higgsfield: completed response missing video URL', [
                'keys' => array_keys($data),
                'payload' => $data,
            ]);
            throw new ProviderException('Higgsfield returned no video URL', self::PROVIDER_NAME);
        }

        return [[
            'url' => $url,
            'duration' => isset($requestBody['duration']) ? (int) $requestBody['duration'] : null,
            'resolution' => isset($requestBody['resolution']) && is_string($requestBody['resolution'])
                ? $requestBody['resolution']
                : null,
        ]];
    }

    /**
     * Map a Higgsfield error status code to a typed ProviderException.
     *
     * @param array<string, mixed> $data
     */
    private function handleErrorResponse(int $statusCode, array $data): never
    {
        $message = (string) (
            $data['error'] ?? $data['message'] ?? $data['detail'] ?? 'Unknown error'
        );

        if (401 === $statusCode || 403 === $statusCode) {
            throw new ProviderException("Higgsfield authentication error ({$statusCode}): {$message}. ".'Check that HIGGSFIELD_API_KEY and HIGGSFIELD_API_SECRET are set to the matching pair from cloud.higgsfield.ai.', self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        if (402 === $statusCode) {
            throw new ProviderException('Higgsfield account is out of credits. Top up at https://cloud.higgsfield.ai/.', self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        if (404 === $statusCode) {
            throw new ProviderException("Higgsfield model not found ({$statusCode}): {$message}", self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        if (429 === $statusCode) {
            throw new ProviderException('Higgsfield rate limit exceeded. Please try again later.', self::PROVIDER_NAME, ['status_code' => $statusCode]);
        }

        throw new ProviderException("Higgsfield API error ({$statusCode}): {$message}", self::PROVIDER_NAME, ['status_code' => $statusCode, 'response' => $data]);
    }

    // ==================== HELPERS ====================

    /**
     * @param array{api_key: string, api_secret: string, source?: string} $credentials
     *
     * @return array<string, string>
     */
    private function buildAuthHeaders(array $credentials): array
    {
        return [
            'Authorization' => 'Key '.$credentials['api_key'].':'.$credentials['api_secret'],
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    /**
     * Resolve credentials from $options['credentials'] (preferred — set by
     * AiFacade after consulting HiggsfieldCredentialResolver) or fall back to
     * the platform-wide env values injected at construction time.
     *
     * @return array{api_key: string, api_secret: string, source: string}
     */
    private function credentialsFromOptions(array $options): array
    {
        $supplied = $options['credentials'] ?? null;
        if (is_array($supplied)
            && isset($supplied['api_key'], $supplied['api_secret'])
            && '' !== $supplied['api_key']
            && '' !== $supplied['api_secret']
        ) {
            return [
                'api_key' => (string) $supplied['api_key'],
                'api_secret' => (string) $supplied['api_secret'],
                'source' => isset($supplied['source']) ? (string) $supplied['source'] : 'inline',
            ];
        }

        if (!$this->hasPlatformCredentials()) {
            throw ProviderException::missingApiKey(self::PROVIDER_NAME, 'HIGGSFIELD_API_KEY');
        }

        return [
            'api_key' => $this->platformApiKey,
            'api_secret' => $this->platformApiSecret,
            'source' => 'platform',
        ];
    }

    private function modelFromOptions(array $options, string $default): string
    {
        $model = $options['model'] ?? null;
        if (is_string($model) && '' !== trim($model)) {
            return trim($model);
        }

        $modelConfig = $options['modelConfig'] ?? [];
        if (is_array($modelConfig)) {
            $params = $modelConfig['params'] ?? null;
            if (is_array($params) && isset($params['model']) && is_string($params['model']) && '' !== $params['model']) {
                return $params['model'];
            }
        }

        return $default;
    }

    private function modelEndpoint(string $model): string
    {
        $trimmed = ltrim($model, '/');
        if (str_starts_with($trimmed, 'http://') || str_starts_with($trimmed, 'https://')) {
            return $trimmed;
        }

        return self::BASE_URL.'/'.$trimmed;
    }

    /**
     * Derive aspect ratio from the explicit option or, as a courtesy, from a
     * "WxH" size hint like "1024x1024".
     */
    private function aspectRatioFromOptions(array $options): ?string
    {
        if (isset($options['aspect_ratio']) && is_string($options['aspect_ratio']) && '' !== $options['aspect_ratio']) {
            return $options['aspect_ratio'];
        }

        $size = $options['size'] ?? null;
        if (is_string($size) && preg_match('/^(\d+)x(\d+)$/', $size, $m)) {
            return $this->sizeToAspectRatio((int) $m[1], (int) $m[2]);
        }

        $width = isset($options['width']) ? (int) $options['width'] : 0;
        $height = isset($options['height']) ? (int) $options['height'] : 0;
        if ($width > 0 && $height > 0) {
            return $this->sizeToAspectRatio($width, $height);
        }

        return null;
    }

    private function resolutionFromOptions(array $options): ?string
    {
        if (isset($options['resolution']) && is_string($options['resolution']) && '' !== $options['resolution']) {
            return $options['resolution'];
        }

        $modelConfig = $options['modelConfig'] ?? [];
        if (is_array($modelConfig) && isset($modelConfig['default_resolution']) && is_string($modelConfig['default_resolution'])) {
            return $modelConfig['default_resolution'];
        }

        return null;
    }

    /**
     * Resolve the clip duration (seconds) to a value Higgsfield supports.
     *
     * DoP renders 5s clips; Kling supports 5s or 10s. A request for any other
     * length (including the generic 8s default that flows down from the media
     * handler) is snapped to the nearest supported value, defaulting to the
     * standard 5s clip when the caller didn't ask for a specific length.
     *
     * @param array<string, mixed> $options
     */
    private function durationFromOptions(string $model, array $options): int
    {
        $allowed = $this->allowedDurations($model, $options);
        sort($allowed);

        $modelConfig = is_array($options['modelConfig'] ?? null) ? $options['modelConfig'] : [];
        $default = isset($modelConfig['default_duration']) && is_numeric($modelConfig['default_duration'])
            ? (int) $modelConfig['default_duration']
            : self::DEFAULT_VIDEO_DURATION;
        if (!in_array($default, $allowed, true)) {
            $default = $allowed[0];
        }

        $requested = isset($options['duration']) && is_numeric($options['duration'])
            ? (int) $options['duration']
            : $default;

        if (in_array($requested, $allowed, true)) {
            return $requested;
        }

        // Snap to the nearest supported value; ties resolve to the shorter clip.
        $nearest = $allowed[0];
        foreach ($allowed as $value) {
            if (abs($value - $requested) < abs($nearest - $requested)) {
                $nearest = $value;
            }
        }

        $this->logger->info('Higgsfield: normalised requested video duration to a supported length', [
            'model' => $model,
            'requested' => $requested,
            'used' => $nearest,
            'allowed' => $allowed,
        ]);

        return $nearest;
    }

    /**
     * Supported clip lengths for the model: an explicit modelConfig override if
     * present, otherwise derived from the model family (DoP = 5s only, everything
     * else 5s or 10s). The fallback keeps models seeded before durations were
     * added behaving correctly without a re-seed.
     *
     * @param array<string, mixed> $options
     *
     * @return int[]
     */
    private function allowedDurations(string $model, array $options): array
    {
        $modelConfig = is_array($options['modelConfig'] ?? null) ? $options['modelConfig'] : [];
        $configured = $modelConfig['allowed_durations'] ?? null;
        if (is_array($configured)) {
            $values = [];
            foreach ($configured as $value) {
                if (is_numeric($value) && (int) $value > 0) {
                    $values[] = (int) $value;
                }
            }
            if ([] !== $values) {
                return array_values(array_unique($values));
            }
        }

        return false !== stripos($model, 'dop')
            ? self::DOP_VIDEO_DURATIONS
            : self::DEFAULT_VIDEO_DURATIONS;
    }

    private function sizeToAspectRatio(int $width, int $height): string
    {
        if ($width <= 0 || $height <= 0) {
            return '1:1';
        }

        $gcd = static function (int $a, int $b) use (&$gcd): int {
            return 0 === $b ? $a : $gcd($b, $a % $b);
        };

        $g = $gcd($width, $height);
        if ($g <= 0) {
            return '1:1';
        }

        return ($width / $g).':'.($height / $g);
    }

    private function hasPlatformCredentials(): bool
    {
        return '' !== $this->platformApiKey && '' !== $this->platformApiSecret;
    }

    /**
     * Re-arm the PHP execution-time limit for one more poll cycle.
     *
     * The synchronous submit→poll loop can legitimately run for several minutes
     * while a clip renders. Under FrankenPHP the request's max_execution_time is
     * wall-clock and a one-time set_time_limit(0) does not disable it, so the
     * loop must restart the timer each iteration (set_time_limit() resets the
     * counter to zero) to avoid a mid-render "Maximum execution time exceeded".
     * Guarded by function_exists because set_time_limit can be disabled.
     */
    private function extendExecutionTime(int $seconds): void
    {
        if (\function_exists('set_time_limit')) {
            set_time_limit(max(30, $seconds));
        }
    }
}
