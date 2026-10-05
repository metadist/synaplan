<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\LockInterface;

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

            $claim = $this->claimForTimeout($job, $job->isPastDeadline($now));
            if (!$claim['reap']) {
                continue;
            }

            try {
                $this->cancelProvider($job);
                $timedOut = $this->jobService->markTimedOut(
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
                if ($timedOut) {
                    $this->messageSync->syncTerminalState($job);
                    ++$reaped;
                }
            } finally {
                $claim['lock']?->release();
            }
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
     * Decide whether this candidate is timed out, and hold the advance lock
     * across that write when the worker is not already inside generate().
     *
     * Image and audio stay in `submitting` for the whole provider call. A held
     * advance lock means that call is still running: leave the job alone until
     * its deadline. When the lock is free, this process keeps it until the
     * timeout is stored so a redelivered worker cannot start the same render
     * in the gap.
     *
     * @return array{reap: bool, lock: ?LockInterface}
     */
    private function claimForTimeout(MediaJob $job, bool $pastDeadline): array
    {
        if (MediaJob::TYPE_VIDEO === $job->getType() || MediaJob::STATUS_SUBMITTING !== $job->getStatus()) {
            return ['reap' => true, 'lock' => null];
        }

        $lock = $this->lockFactory->createLock(MediaJob::ADVANCE_LOCK_PREFIX.$job->getJobKey(), 30.0);
        if ($lock->acquire(false)) {
            return ['reap' => true, 'lock' => $lock];
        }

        return ['reap' => $pastDeadline, 'lock' => null];
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
