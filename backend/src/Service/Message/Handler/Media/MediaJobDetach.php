<?php

namespace App\Service\Message\Handler\Media;

use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use App\Service\Message\ProgressCallback;
use Psr\Log\LoggerInterface;

/**
 * Hands a media render to a background job and answers at once with a
 * `running` placeholder: video runs the submit, poll and finalize loop on the
 * worker, image and audio a single worker step. A user at the job ceiling or
 * an unreachable queue gets a clear sentence in the same turn; the job
 * carries the billable usage the worker records when it ends (#1146).
 */
final readonly class MediaJobDetach
{
    public function __construct(
        private MediaJobConfig $mediaJobConfig,
        private MediaJobService $mediaJobService,
        private MediaJobDispatcher $mediaJobDispatcher,
        private MediaJobMessageSync $mediaJobMessageSync,
        private MediaErrorMessageBuilder $errorMessageBuilder,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The async backbone is the default, but `options['force_inline_media']`
     * forces the blocking path: a multitask media node another node depends on
     * must produce its file in the same turn (#1218).
     */
    public function allowed(MediaTurnRequest $request): bool
    {
        return $this->asyncJobsAllowed($request->effectiveUserId, $request->options);
    }

    /**
     * @param string               $type         MediaJob::TYPE_IMAGE, TYPE_VIDEO or TYPE_AUDIO
     * @param array<string, mixed> $mediaOptions provider options (image/video/audio specifics)
     *
     * @return array{metadata: array<string, mixed>}|array{text: string, metadata: array<string, mixed>}
     */
    public function detach(string $type, MediaTurnRequest $request, array $mediaOptions, ?string $inputRef, callable $streamCallback, ?callable $progressCallback): array
    {
        $message = $request->message;
        $classification = $request->classification;
        $options = $request->options;
        $prompt = $request->prompt;
        $provider = (string) $request->model->provider;
        $modelName = $request->model->name;
        $modelId = $request->model->id;
        $effectiveUserId = $request->effectiveUserId;
        $mediaOptions['lang'] = is_string($classification['language'] ?? null)
            ? $classification['language']
            : ($message->getLanguage() ?: 'en');
        $lang = (string) $mediaOptions['lang'];

        // Per-user concurrency ceiling: never queue past the limit so one user
        // can't flood the worker or pile up provider cost. The rate-limit gate
        // (checkLimit) already ran upstream; this bounds in-flight parallelism.
        $maxActive = $this->mediaJobConfig->maxActiveJobsPerUser();
        if ($this->mediaJobService->countActiveForUser($effectiveUserId) >= $maxActive) {
            return $this->atCeiling($type, $request, $lang, $maxActive, $streamCallback, $progressCallback);
        }

        $chatId = null;
        $chat = $message->getChat();
        if (null !== $chat) {
            $chatId = $chat->getId();
        }

        $nodeId = isset($options['node_id']) && is_scalar($options['node_id'])
            ? trim((string) $options['node_id'])
            : null;
        $trackId = isset($options['track_id']) && is_scalar($options['track_id'])
            ? trim((string) $options['track_id'])
            : null;

        // Stash the billable usage payload so the worker can record it when the
        // job completes (Sprint E, #1146). Mirrors the inline path's media_usage
        // shape so RateLimitService computes the identical cost.
        $mediaOptions['media_usage'] = $this->detachMediaUsage($type, $prompt, $classification, $options, $mediaOptions);

        $job = $this->mediaJobService->create([
            'userId' => $effectiveUserId,
            'type' => $type,
            'provider' => $provider,
            'prompt' => $prompt,
            'modelId' => $modelId,
            'model' => $modelName,
            'chatId' => $chatId,
            'messageId' => $message->getId(),
            'nodeId' => '' !== (string) $nodeId ? $nodeId : null,
            'trackId' => '' !== $trackId ? $trackId : null,
            'inputRef' => $inputRef,
            'options' => $mediaOptions,
        ]);

        if (!$this->mediaJobDispatcher->dispatch($job)) {
            return $this->undeliverable($job, $type, $request, $lang, $streamCallback, $progressCallback);
        }

        $mediaJob = [
            'job_id' => $job->getJobKey(),
            'type' => $type,
            'state' => 'running',
        ];

        // Placeholder token — the frontend renders a dedicated background-job
        // banner (MediaJobStatus) and persists this marker so reloads are not empty.
        $streamCallback($this->generatingTokenFor($type));

        ProgressCallback::notify($progressCallback, 'generating', 'Media generation started in the background.', [
            'media_job' => $mediaJob,
        ]);

        $this->logger->info('MediaGenerationHandler: Detached media to async job', [
            'job_key' => $job->getJobKey(),
            'type' => $type,
            'provider' => $provider,
            'model' => $modelName,
            'message_id' => $message->getId(),
            'node_id' => $nodeId,
        ]);

        return [
            'metadata' => [
                'provider' => $provider,
                'model' => $modelName,
                'model_id' => $modelId,
                'media_prompt' => $prompt,
                'media_type' => $type,
                'media_job' => $mediaJob,
            ],
        ];
    }

    /**
     * @return array{text: string, metadata: array<string, mixed>}
     */
    private function atCeiling(string $type, MediaTurnRequest $request, string $lang, int $maxActive, callable $streamCallback, ?callable $progressCallback): array
    {
        $limitMessage = $this->errorMessageBuilder->buildTooManyJobsMessage($lang);
        $streamCallback($limitMessage);
        ProgressCallback::notify($progressCallback, 'error', $limitMessage);
        $this->logger->info('MediaGenerationHandler: media job rejected — per-user concurrency limit reached', [
            'user_id' => $request->effectiveUserId,
            'limit' => $maxActive,
            'type' => $type,
        ]);

        return [
            'text' => $limitMessage,
            'metadata' => [
                'provider' => (string) $request->model->provider,
                'model' => $request->model->name,
                'model_id' => $request->model->id,
                'media_prompt' => $request->prompt,
                'media_type' => $type,
                'error' => $limitMessage,
            ],
        ];
    }

    /**
     * The transport (Redis) is unreachable: the job fails in the same turn with
     * a clear sentence instead of an empty bubble that polls for a worker that
     * will never pick it up.
     *
     * @return array{text: string, metadata: array<string, mixed>}
     */
    private function undeliverable(MediaJob $job, string $type, MediaTurnRequest $request, string $lang, callable $streamCallback, ?callable $progressCallback): array
    {
        $errorMessage = $this->errorMessageBuilder->buildErrorMessage(
            new \RuntimeException('Background queue unreachable'),
            $type,
            $lang,
        );
        $this->mediaJobService->markFailed($job, $errorMessage);
        $this->mediaJobMessageSync->syncTerminalState($job);

        $mediaJob = [
            'job_id' => $job->getJobKey(),
            'type' => $type,
            'state' => 'failed',
            'error' => $errorMessage,
        ];
        $streamCallback($errorMessage);
        ProgressCallback::notify($progressCallback, 'error', $errorMessage, [
            'media_job' => $mediaJob,
        ]);

        $this->logger->error('MediaGenerationHandler: detach failed — queue dispatch rejected', [
            'job_key' => $job->getJobKey(),
            'provider' => $request->model->provider,
            'model' => $request->model->name,
            'message_id' => $request->message->getId(),
            'type' => $type,
        ]);

        return [
            'text' => $errorMessage,
            'metadata' => [
                'provider' => (string) $request->model->provider,
                'model' => $request->model->name,
                'model_id' => $request->model->id,
                'media_prompt' => $request->prompt,
                'media_type' => $type,
                'media_job' => $mediaJob,
                'error' => $errorMessage,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    private function asyncJobsAllowed(int $effectiveUserId, array $options): bool
    {
        if (!empty($options['force_inline_media'])) {
            return false;
        }

        return $this->mediaJobConfig->isAsyncJobsEnabled($effectiveUserId);
    }

    /**
     * Billable usage payload to persist on a detached job, mirroring the
     * inline success-path `media_usage` shape. Values are known at request
     * time: image = 1 asset, audio = prompt length, video = requested duration
     * (the inline path falls back to the same).
     *
     * @param array<string, mixed> $classification
     * @param array<string, mixed> $options
     * @param array<string, mixed> $mediaOptions   the resolved provider options
     *
     * @return array<string, mixed>
     */
    private function detachMediaUsage(string $type, string $prompt, array $classification, array $options, array $mediaOptions): array
    {
        return match ($type) {
            MediaJob::TYPE_VIDEO => [
                'duration_seconds' => (float) ($options['duration'] ?? $classification['duration'] ?? 8),
            ],
            MediaJob::TYPE_AUDIO => ['characters' => mb_strlen($prompt)],
            // Carry quality/size so per-tier image models (gpt-image) bill the
            // exact price when the async job is later recorded (#1315). Read
            // from the RESOLVED provider options, not the raw request options:
            // the image options already applied the pic2pic default ('high'),
            // and billing must follow what was actually sent to the provider.
            // The size is the request's; SyncMediaJobGenerator replaces it with
            // the size the model rendered when the provider reports one (#2404).
            default => [
                'images' => 1,
                'quality' => $mediaOptions['quality'] ?? $options['quality'] ?? 'standard',
                'size' => $mediaOptions['size'] ?? $options['size'] ?? '1024x1024',
            ],
        };
    }

    /**
     * Frontend placeholder token per media type. The MediaJobStatus banner is
     * the real running UI; this token is the streamed and persisted marker
     * that MessageText maps to a localized "generating…" line, so a reload is
     * never an empty bubble.
     */
    private function generatingTokenFor(string $type): string
    {
        return match ($type) {
            MediaJob::TYPE_IMAGE => '__IMAGE_GENERATING__',
            MediaJob::TYPE_AUDIO => '__AUDIO_GENERATING__',
            default => '__VIDEO_GENERATING__',
        };
    }
}
