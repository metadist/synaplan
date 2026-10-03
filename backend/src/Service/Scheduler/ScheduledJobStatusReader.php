<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

use Psr\Clock\ClockInterface;
use Symfony\Component\Clock\Clock;

/**
 * Reads every scheduled command and decides whether the tick lane is alive.
 *
 * `running` / `stale` / `never` come from the newest `lastFinishedAt` among
 * tick-lane jobs, compared with the caller's max age. Other lanes are listed
 * but do not move that summary: a long task must not look like a dead loop.
 */
final readonly class ScheduledJobStatusReader
{
    public const STATE_RUNNING = 'running';
    public const STATE_STALE = 'stale';
    public const STATE_NEVER = 'never';

    public function __construct(
        private ScheduledJobStatusStore $status,
        private ClockInterface $clock = new Clock(),
    ) {
    }

    public function read(int $maxAgeSeconds): ScheduledJobStatusReport
    {
        if ($maxAgeSeconds < 1) {
            throw new \InvalidArgumentException('max-age must be an integer greater than or equal to 1.');
        }

        $this->status->ensureAvailable();
        $jobs = $this->jobs();

        return new ScheduledJobStatusReport(
            $this->state($jobs, $maxAgeSeconds, $this->clock->now()->getTimestamp()),
            $jobs,
        );
    }

    /**
     * @return list<ScheduledJobStatus>
     */
    private function jobs(): array
    {
        $jobs = [];
        foreach (ScheduledJobs::LANES as $lane => $commands) {
            foreach ($commands as $command) {
                $payload = $this->status->read($command);
                $started = null;
                $finished = null;
                $exitCode = null;
                $host = null;
                if (null !== $payload) {
                    $started = $payload['lastStartedAt'];
                    $finished = $payload['lastFinishedAt'];
                    $exitCode = $payload['lastExitCode'];
                    $host = $payload['host'];
                }

                $jobs[] = new ScheduledJobStatus($command, $lane, $started, $finished, $exitCode, $host);
            }
        }

        return $jobs;
    }

    /**
     * @param list<ScheduledJobStatus> $jobs
     */
    private function state(array $jobs, int $maxAgeSeconds, int $now): string
    {
        $latestFinish = null;
        foreach ($jobs as $job) {
            if (ScheduledJobs::LANE_TICK !== $job->lane || null === $job->lastFinishedAt) {
                continue;
            }
            if (null === $latestFinish || $job->lastFinishedAt > $latestFinish) {
                $latestFinish = $job->lastFinishedAt;
            }
        }

        if (null === $latestFinish) {
            return self::STATE_NEVER;
        }

        if (($now - $latestFinish) > $maxAgeSeconds) {
            return self::STATE_STALE;
        }

        return self::STATE_RUNNING;
    }
}
