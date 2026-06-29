<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\AI\Service\AiFacade;
use App\Service\Message\Handler\MediaErrorMessageBuilder;
use Psr\Log\LoggerInterface;

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
