<?php

namespace App\AI\Provider\Google;

use App\AI\Exception\ProviderException;
use Psr\Log\LoggerInterface;

/**
 * Gemini Omni video for GoogleVideoGeneration: a clip starts as a
 * background interaction on the Interactions API, is polled until a step
 * carries the video (by URI or inline) and is cancelled by deleting the
 * interaction. Its operation handle carries a prefix, so poll, cancel and
 * download can tell it from a Veo operation.
 */
final class GoogleOmniVideo
{
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

    public function __construct(
        private readonly GoogleApiClient $api,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function handlesModel(string $model): bool
    {
        return str_starts_with(strtolower($model), 'gemini-omni');
    }

    public function ownsOperation(string $operationName): bool
    {
        return str_starts_with($operationName, self::OMNI_OPERATION_PREFIX);
    }

    public function interactionId(string $operationName): string
    {
        return $this->ownsOperation($operationName)
            ? substr($operationName, strlen(self::OMNI_OPERATION_PREFIX))
            : $operationName;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{operationName: string, model: string, duration: int, resolution: string}
     */
    public function start(string $prompt, array $options, string $model, string $key): array
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
    public function pollOnce(string $operationName, string $key): array
    {
        $interactionId = $this->interactionId($operationName);
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
}
