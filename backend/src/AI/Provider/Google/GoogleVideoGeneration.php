<?php

namespace App\AI\Provider\Google;

use App\AI\Exception\ProviderCancelledException;
use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;

/**
 * Google video generation for GoogleProvider: Veo long-running operations
 * (start, poll once, poll until done with the Stop button honoured, cancel,
 * download) with the duration and resolution mapped to what Veo accepts,
 * and Gemini Omni clips through {@see GoogleOmniVideo}.
 */
final class GoogleVideoGeneration
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

    public function __construct(
        private readonly GoogleApiClient $api,
        private readonly LoggerInterface $logger,
        private readonly GoogleOmniVideo $omni,
    ) {
    }

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

        if ($this->omni->handlesModel($model)) {
            return $this->omni->start($prompt, $options, $model, $key);
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

        if ($this->omni->ownsOperation($operationName)) {
            return $this->omni->pollOnce($operationName, $key);
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

        $cancelUrl = $this->omni->ownsOperation($operationName)
            ? $this->api->url('interactions/'.$this->omni->interactionId($operationName))
            : $this->api->url($operationName.':cancel');
        $method = $this->omni->ownsOperation($operationName) ? 'DELETE' : 'POST';

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
