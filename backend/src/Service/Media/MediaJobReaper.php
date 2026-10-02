<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;

/**
 * Backstop that guarantees the "no job runs forever / nothing fails silently"
 * rail of the media-job system (Release 4.0, Feature 1, Sprint A).
 *
 * The advance handler enforces the per-type deadline itself on every step, so
 * the normal path always terminates. This reaper exists for the case the
 * advancer CANNOT cover: the worker process died mid-render (crash, OOM, deploy)
 * and stopped re-dispatching. Such a job sits `running` with a heartbeat that
 * goes stale; nobody would ever move it to a terminal state.
 *
 * On each run it scans the active set for jobs whose heartbeat is older than
 * {@see MediaJobConfig::heartbeatStaleSeconds()} (worker presumed dead) and
 * drives them to `timed_out`, best-effort cancelling the provider operation so
 * we stop being billed for output nobody is waiting for.
 *
 * Synchronous image and audio renders are the exception (#2308). They block
 * inside one provider call and cannot refresh the heartbeat, so a stale
 * heartbeat there does not mean the worker died. While that job is still
 * `submitting`, before its deadline, and its advance lock is held, it is left
 * alone. The deadline remains the hard stop.
 *
 * Run periodically from cron via {@see \App\Command\ReapMediaJobsCommand}.
 */
final readonly class MediaJobReaper
{
    public function __construct(
        private MediaJobService $jobService,
        private MediaJobMessageSync $messageSync,
        private MediaJobConfig $config,
        private AiFacade $aiFacade,
        private MediaErrorMessageBuilder $errorBuilder,
        private LockFactory $lockFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Time out every stale or past-deadline active job and return how many were
     * reaped. Idempotent: terminal jobs are self-healed out of the active set by
     * the store and skipped here.
     */
    public function reap(int $limit = 100): int
    {
        $now = time();
        $cutoff = $now - $this->config->heartbeatStaleSeconds();
        $candidates = [];
        foreach ($this->jobService->findStale($cutoff, $limit) as $job) {
            $candidates[$job->getJobKey()] = $job;
        }
        foreach ($this->jobService->findPastDeadline($limit) as $job) {
            $candidates[$job->getJobKey()] = $job;
        }

        $reaped = 0;
        foreach ($candidates as $job) {
            // Re-check under the latest view: the store may have returned a job
            // that another process just finished, and terminal states are final.
            if ($job->isTerminal()) {
                continue;
            }

            // A live synchronous render has a stale heartbeat by nature: the
            // worker is blocked in the provider call. The advance lock, held
            // until the deadline, is the liveness signal. Past the deadline
            // the job is reaped either way.
            if (!$job->isPastDeadline($now) && $this->synchronousWorkerIsAlive($job)) {
                continue;
            }

            $this->cancelProvider($job);
            $this->jobService->markTimedOut(
                $job,
                $job->isPastDeadline($now)
                    ? $this->errorBuilder->buildTimeoutMessage(
                        $job->getType(),
                        $this->jobService->langFromJob($job),
                    )
                    : $this->errorBuilder->buildErrorMessage(
                        new \RuntimeException('Render worker stopped responding'),
                        $job->getType(),
                        $this->jobService->langFromJob($job),
                    ),
            );
            $this->messageSync->syncTerminalState($job);
            ++$reaped;
        }

        if ($reaped > 0) {
            $this->logger->warning('MediaJobReaper timed out stale jobs', [
                'reaped' => $reaped,
                'heartbeat_cutoff' => $cutoff,
            ]);
        }

        return $reaped;
    }

    /**
     * True when an image or audio job is inside its blocking provider call and
     * the worker still holds the advance lock. Video jobs poll and heartbeat,
     * so they are never treated as alive by this check.
     */
    private function synchronousWorkerIsAlive(MediaJob $job): bool
    {
        if (MediaJob::TYPE_VIDEO === $job->getType() || MediaJob::STATUS_SUBMITTING !== $job->getStatus()) {
            return false;
        }

        $lock = $this->lockFactory->createLock(MediaJob::ADVANCE_LOCK_PREFIX.$job->getJobKey(), 30.0);
        if ($lock->acquire(false)) {
            // Nobody held it. Drop it immediately so the real worker can take it.
            $lock->release();

            return false;
        }

        return true;
    }

    private function cancelProvider(MediaJob $job): void
    {
        $operationName = $job->getProviderRef();
        if (null === $operationName || '' === $operationName) {
            return;
        }

        // AiFacade::cancelVideoOperation is best-effort and never throws.
        $this->aiFacade->cancelVideoOperation(
            $operationName,
            $job->getProvider(),
            $job->getUserId(),
            $job->getOptions(),
        );
    }
}
