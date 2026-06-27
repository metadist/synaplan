<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\AI\Service\AiFacade;
use App\Message\AdvanceMediaJobCommand;
use App\Service\File\FileHelper;
use App\Service\File\UserUploadPathBuilder;
use App\Service\Media\MediaJob;
use App\Service\Media\MediaJobConfig;
use App\Service\Media\MediaJobDispatcher;
use App\Service\Media\MediaJobMessageSync;
use App\Service\Media\MediaJobService;
use App\Service\Media\SyncMediaJobGenerator;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Advances one {@see MediaJob} by a single non-blocking step and re-arms the
 * next step while the job is still active. This is the worker the
 * {@see MediaJob}/{@see MediaJobService}/{@see \App\Service\Media\MediaJobStore}
 * backbone was always designed for (Release 4.0, Feature 1, Sprint A).
 *
 * Why ONE step per message (not a blocking poll loop)
 * ---------------------------------------------------
 * The whole point of the job system is to NOT pin a FrankenPHP/worker process
 * for the 5–8 minutes a render can take. Each invocation does exactly one of:
 *   - submit   (queued/submitting → running): start the provider operation;
 *   - poll     (running): one stateless poll; re-dispatch after the poll
 *              interval, or move to finalizing when the provider says done;
 *   - finalize (finalizing): download the bytes, save to disk, mark completed.
 * After a non-terminal step it re-dispatches itself (delayed for polls), so the
 * loop advances in short hops driven by Messenger, never by `sleep()`.
 *
 * Safety rails (the "always reach a terminal state" guarantee):
 *   - a per-job lock serialises advances so the reaper and a re-dispatch can
 *     never double-advance the same job;
 *   - a job past its deadline is driven straight to `timed_out` here (the
 *     reaper is the backstop for workers that die mid-render);
 *   - every provider call is wrapped → failures become a localized, non-leaky
 *     {@see MediaErrorMessageBuilder} message on the job, never an escaping
 *     exception that Messenger would pointlessly retry.
 *
 * Scope note (Sprint A): wired for the async-video providers (Higgsfield + Veo)
 * via {@see AiFacade}'s {@see \App\AI\Interface\SupportsAsyncVideo} surface.
 * Nothing creates jobs yet (the chat path is detached in Sprint B behind
 * {@see MediaJobConfig::isAsyncJobsEnabled()}), so this handler is inert in
 * production until then.
 */
#[AsMessageHandler]
final readonly class AdvanceMediaJobCommandHandler
{
    /** Lock lifetime per advance step — one step is quick; this is just a crash guard. */
    private const ADVANCE_LOCK_TTL_SECONDS = 120;

    /**
     * How many CONSECUTIVE transient failures (network blip, provider 5xx,
     * Google INTERNAL/13) a poll/finalize step may hit before we give up and
     * surface a localized failure. Each retry re-dispatches with backoff; the
     * per-type deadline is the ultimate bound. The point: a transient provider
     * hiccup must NOT fail a render the provider is actually completing.
     */
    private const MAX_TRANSIENT_FAILURES = 6;

    /** Upper bound on the exponential re-dispatch backoff between transient retries. */
    private const MAX_TRANSIENT_BACKOFF_SECONDS = 60;

    /** Options key tracking the current consecutive transient-failure streak. */
    private const OPT_TRANSIENT_FAILURES = '_transientFailures';

    /** Options key holding the last raw provider error (dev/admin diagnostics; never shown to users). */
    private const OPT_LAST_ERROR = '_lastError';

    public function __construct(
        private MediaJobService $jobService,
        private MediaJobDispatcher $dispatcher,
        private MediaJobConfig $config,
        private MediaJobMessageSync $messageSync,
        private AiFacade $aiFacade,
        private MediaErrorMessageBuilder $errorBuilder,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private SyncMediaJobGenerator $syncGenerator,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
    ) {
    }

    public function __invoke(AdvanceMediaJobCommand $message): void
    {
        $jobKey = $message->getJobKey();

        // Serialise advances for this one job: the reaper and a re-dispatched
        // step must never advance the same job concurrently. A missed lock is
        // not an error — whoever holds it will re-arm the next step.
        $lock = $this->lockFactory->createLock('media-job-advance.'.$jobKey, self::ADVANCE_LOCK_TTL_SECONDS);
        if (!$lock->acquire()) {
            // Another advance for this job holds the lock. Do NOT silently drop
            // this step — re-dispatch after a short delay so the loop is never
            // lost if the holder fails to re-arm. Idempotent: once the job is
            // terminal, the re-dispatched step loads it and returns without
            // scheduling anything further, so this self-terminates.
            $this->dispatcher->dispatchKey($jobKey, max(1, $this->config->pollIntervalSeconds()));

            return;
        }

        try {
            $job = $this->jobService->findByKey($jobKey);
            if (null === $job) {
                // Snapshot expired (Redis eviction / TTL) — nothing to advance.
                return;
            }
            if ($job->isTerminal()) {
                // The job is already terminal in Redis. If we are processing an
                // advance command, a previous attempt failed AFTER marking it
                // terminal (likely during DB sync or billing). Retry the sync
                // to close the loophole, then stop advancing.
                $this->messageSync->syncTerminalState($job);

                return;
            }
            if ($job->isPastDeadline()) {
                $this->timeOut($job);

                return;
            }

            match ($job->getStatus()) {
                MediaJob::STATUS_QUEUED, MediaJob::STATUS_SUBMITTING => $this->advanceInitial($job),
                MediaJob::STATUS_RUNNING => $this->poll($job),
                MediaJob::STATUS_FINALIZING => $this->finalize($job),
                default => null,
            };
        } finally {
            $lock->release();
        }
    }

    /**
     * First advance step. Video uses the asynchronous submit→poll→finalize loop;
     * image and audio are a single synchronous provider call, so they render and
     * complete in this one step (no polling).
     */
    private function advanceInitial(MediaJob $job): void
    {
        if (MediaJob::TYPE_VIDEO === $job->getType()) {
            $this->submit($job);

            return;
        }

        $this->generateSync($job);
    }

    /**
     * Synchronous one-step render for image/audio jobs: generate, save, and go
     * straight to a terminal `completed` state with the produced file.
     */
    private function generateSync(MediaJob $job): void
    {
        $this->jobService->markSubmitting($job);

        try {
            $result = $this->syncGenerator->generate($job);
        } catch (\Throwable $e) {
            $this->fail($job, $e);

            return;
        }

        $this->jobService->markCompleted($job, $result);
        $this->messageSync->syncTerminalState($job);
    }

    private function submit(MediaJob $job): void
    {
        $this->jobService->markSubmitting($job);

        $options = $job->getOptions();
        $options['provider'] = $job->getProvider();
        if (null !== $job->getModel()) {
            $options['model'] = $job->getModel();
        }

        try {
            $operation = $this->aiFacade->startVideoGeneration(
                (string) ($job->getPrompt() ?? ''),
                $job->getUserId(),
                $options,
            );
        } catch (\Throwable $e) {
            $this->fail($job, $e);

            return;
        }

        $operationName = $operation['operationName'];
        if ('' === $operationName) {
            $this->fail($job, new \RuntimeException('Provider returned no operation handle'));

            return;
        }

        $this->jobService->markRunning($job, $operationName);
        if (!$this->dispatcher->dispatch($job, $this->config->pollIntervalSeconds())) {
            $this->fail($job, new \RuntimeException('Queue dispatch rejected — cannot schedule next poll'));
        }
    }

    private function poll(MediaJob $job): void
    {
        $operationName = $job->getProviderRef();
        if (null === $operationName || '' === $operationName) {
            $this->fail($job, new \RuntimeException('Running job has no provider operation handle'));

            return;
        }

        try {
            $result = $this->aiFacade->pollVideoOperation(
                $operationName,
                $job->getProvider(),
                $job->getUserId(),
                $job->getOptions(),
            );
        } catch (\Throwable $e) {
            // Network/transport blip or provider 5xx — the render is very likely
            // still progressing. Retry with backoff instead of failing the job.
            $this->retryTransientOrFail($job, $e, 'poll');

            return;
        }

        $percent = isset($result['percent']) ? (int) $result['percent'] : null;
        $status = $result['status'] ?? null;
        $this->jobService->updateProgress($job, $percent, $status);

        $error = $result['error'];
        if (is_string($error) && '' !== $error) {
            // A provider-reported error string. These are frequently transient
            // (notably Google Veo INTERNAL/13, which self-recovers), so treat
            // them as retryable up to the cap/deadline rather than failing a
            // render the provider may still complete.
            $this->retryTransientOrFail($job, new \RuntimeException($error), 'poll');

            return;
        }

        // Clean poll — reset any transient-failure streak.
        $this->clearTransientFailures($job);

        if (true !== $result['done']) {
            // Still rendering — poll again after the interval.
            if (!$this->dispatcher->dispatch($job, $this->config->pollIntervalSeconds())) {
                $this->fail($job, new \RuntimeException('Queue dispatch rejected — cannot schedule next poll'));
            }

            return;
        }

        $videoUri = $result['videoUri'] ?? null;
        if (!is_string($videoUri) || '' === $videoUri) {
            $this->fail($job, new \RuntimeException('Render finished without an output reference'));

            return;
        }

        // Stash the output handle so the finalize step (a separate message) can
        // download it without re-polling. The produced file is the only durable
        // artefact; the job record is ephemeral.
        $options = $job->getOptions();
        $options['_outputUri'] = $videoUri;
        $job->setOptions($options);
        $this->jobService->markFinalizing($job);

        // Finalize immediately — no need to wait a poll interval.
        if (!$this->dispatcher->dispatch($job, 0)) {
            $this->fail($job, new \RuntimeException('Queue dispatch rejected — cannot schedule finalize'));
        }
    }

    private function finalize(MediaJob $job): void
    {
        $outputUri = $job->getOptions()['_outputUri'] ?? null;
        if (!is_string($outputUri) || '' === $outputUri) {
            $this->fail($job, new \RuntimeException('Finalizing job lost its output reference'));

            return;
        }

        try {
            $bytes = $this->aiFacade->downloadVideoRaw(
                $outputUri,
                $job->getProvider(),
                $job->getUserId(),
                $job->getOptions(),
            );
            $relativePath = $this->saveBytes($bytes, $job->getUserId(), $job->getProvider(), $job->getType());
        } catch (\Throwable $e) {
            // The render is DONE at the provider — a download blip must not throw
            // away a finished video. Retry finalize with backoff (providerRef +
            // _outputUri are preserved on the job).
            $this->retryTransientOrFail($job, $e, 'finalize');

            return;
        }

        if (null === $relativePath) {
            $this->fail($job, new \RuntimeException('Failed to save generated media to disk'));

            return;
        }

        $this->jobService->markCompleted($job, [
            'file' => [
                'url' => '/api/v1/files/uploads/'.$relativePath,
                'type' => $job->getType(),
                'mimeType' => $this->mimeTypeFor($job->getType()),
            ],
            'provider' => $job->getProvider(),
            'model' => $job->getModel(),
        ]);
        $this->messageSync->syncTerminalState($job);
    }

    /**
     * Drive a job to `timed_out` and best-effort tell the provider to stop so we
     * stop being billed for output nobody is waiting for.
     */
    private function timeOut(MediaJob $job): void
    {
        $this->cancelProvider($job);
        $this->jobService->markTimedOut(
            $job,
            $this->errorBuilder->buildTimeoutMessage(
                $job->getType(),
                $this->jobService->langFromJob($job),
            ),
        );
        $this->messageSync->syncTerminalState($job);
    }

    /**
     * Retry a transient poll/finalize failure with exponential backoff, only
     * giving up (localized failure) once the consecutive-failure cap is hit.
     * The heartbeat is bumped each retry so the reaper does not kill a job that
     * is actively retrying, and the per-type deadline remains the hard bound.
     */
    private function retryTransientOrFail(MediaJob $job, \Throwable $e, string $phase): void
    {
        $options = $job->getOptions();
        $failures = (int) ($options[self::OPT_TRANSIENT_FAILURES] ?? 0) + 1;

        // Raw cause to logs ONLY (never to the user), with full context so a
        // failure is never opaque.
        $this->logger->warning('MediaJob transient advance failure', [
            'job_key' => $job->getJobKey(),
            'phase' => $phase,
            'attempt' => $failures,
            'max_attempts' => self::MAX_TRANSIENT_FAILURES,
            'provider' => $job->getProvider(),
            'provider_ref' => $job->getProviderRef(),
            'status' => $job->getStatus(),
            'error' => $e->getMessage(),
            'exception' => $e::class,
        ]);

        if ($failures >= self::MAX_TRANSIENT_FAILURES) {
            $this->fail($job, new \RuntimeException(sprintf(
                '%s failed after %d transient retries: %s',
                $phase,
                $failures,
                $e->getMessage(),
            ), 0, $e));

            return;
        }

        $options[self::OPT_TRANSIENT_FAILURES] = $failures;
        $options[self::OPT_LAST_ERROR] = mb_substr($e->getMessage(), 0, 1000);
        $job->setOptions($options);
        // Persist the counter AND refresh the heartbeat so the reaper treats the
        // job as alive while it retries.
        $this->jobService->heartbeat($job);

        if (!$this->dispatcher->dispatch($job, $this->backoffSeconds($failures))) {
            // The queue itself is unreachable — only now is the job truly stuck,
            // because nothing can advance it. Surface a localized failure.
            $this->fail($job, new \RuntimeException('Queue dispatch rejected during transient retry', 0, $e));
        }
    }

    private function clearTransientFailures(MediaJob $job): void
    {
        $options = $job->getOptions();
        if (!isset($options[self::OPT_TRANSIENT_FAILURES])) {
            return;
        }

        unset($options[self::OPT_TRANSIENT_FAILURES]);
        $job->setOptions($options);
        $this->jobService->save($job);
    }

    private function backoffSeconds(int $failures): int
    {
        $base = max(1, $this->config->pollIntervalSeconds());
        $delay = $base * (2 ** min($failures, 4));

        return (int) min($delay, self::MAX_TRANSIENT_BACKOFF_SECONDS);
    }

    private function fail(MediaJob $job, \Throwable $e): void
    {
        $exception = $e instanceof \Exception ? $e : new \RuntimeException($e->getMessage(), 0, $e);
        $lang = is_string($job->getOptions()['lang'] ?? null) ? (string) $job->getOptions()['lang'] : 'en';

        $message = $this->errorBuilder->buildErrorMessage($exception, $job->getType(), $lang);

        // Persist the raw cause as dev/admin meta (NOT exposed by toStatusArray,
        // so it never reaches the chat client) so failures are diagnosable.
        $options = $job->getOptions();
        $options[self::OPT_LAST_ERROR] = mb_substr($e->getMessage(), 0, 1000);
        $job->setOptions($options);

        // Raw cause + full chain to logs ONLY — never to the user.
        $this->logger->warning('MediaJob advance failed', [
            'job_key' => $job->getJobKey(),
            'status' => $job->getStatus(),
            'provider' => $job->getProvider(),
            'provider_ref' => $job->getProviderRef(),
            'error' => $e->getMessage(),
            'exception' => $e::class,
            'previous' => $e->getPrevious()?->getMessage(),
        ]);

        $this->jobService->markFailed($job, $message);
        $this->messageSync->syncTerminalState($job);
    }

    private function cancelProvider(MediaJob $job): void
    {
        $operationName = $job->getProviderRef();
        if (null === $operationName || '' === $operationName) {
            return;
        }

        // AiFacade::cancelVideoOperation is already best-effort (never throws).
        $this->aiFacade->cancelVideoOperation(
            $operationName,
            $job->getProvider(),
            $job->getUserId(),
            $job->getOptions(),
        );
    }

    private function saveBytes(string $content, int $userId, string $provider, string $type): ?string
    {
        $extension = $this->extensionFor($type);
        $sanitized = FileHelper::sanitizeProviderName($provider);
        $filename = sprintf('media_%d_%s_%d.%s', $userId, $sanitized, time(), $extension);
        $relativePath = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId)
            .'/'.date('Y').'/'.date('m').'/'.$filename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        if (!FileHelper::ensureParentDirectory($absolutePath)) {
            return null;
        }

        if (false === FileHelper::writeFile($absolutePath, $content)) {
            return null;
        }

        return $relativePath;
    }

    private function extensionFor(string $type): string
    {
        return match ($type) {
            MediaJob::TYPE_AUDIO => 'mp3',
            MediaJob::TYPE_IMAGE => 'png',
            default => 'mp4',
        };
    }

    private function mimeTypeFor(string $type): string
    {
        return match ($type) {
            MediaJob::TYPE_AUDIO => 'audio/mpeg',
            MediaJob::TYPE_IMAGE => 'image/png',
            default => 'video/mp4',
        };
    }
}
