<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Per-command status plus whether the tick lane looks alive.
 */
final readonly class ScheduledJobStatusReport
{
    /**
     * @param list<ScheduledJobStatus> $jobs
     */
    public function __construct(
        public string $state,
        public array $jobs,
    ) {
    }

    /**
     * Newest finish among tick-lane jobs: the time the summary state is based on.
     */
    public function lastTickFinishedAt(): ?int
    {
        $latest = null;
        foreach ($this->jobs as $job) {
            if (ScheduledJobs::LANE_TICK === $job->lane && null !== $job->lastFinishedAt) {
                $latest = max($latest ?? $job->lastFinishedAt, $job->lastFinishedAt);
            }
        }

        return $latest;
    }

    /**
     * One entry per lane in registry order. A job counts as failed when its
     * last run ended with a non-zero exit code, and as unfinished when it
     * started after its last finish (still running, or stopped by its time
     * limit before it could record a finish).
     *
     * @return list<array{lane: string, lastStartedAt: ?int, lastFinishedAt: ?int, failedJobs: list<string>, unfinishedJobs: list<string>}>
     */
    public function lanes(): array
    {
        /** @var array<string, array{lane: string, lastStartedAt: ?int, lastFinishedAt: ?int, failedJobs: list<string>, unfinishedJobs: list<string>}> $lanes */
        $lanes = [];
        foreach (array_keys(ScheduledJobs::LANES) as $lane) {
            $lanes[$lane] = ['lane' => $lane, 'lastStartedAt' => null, 'lastFinishedAt' => null, 'failedJobs' => [], 'unfinishedJobs' => []];
        }

        foreach ($this->jobs as $job) {
            $lane = $job->lane;
            if (!isset($lanes[$lane])) {
                continue;
            }
            if (null !== $job->lastStartedAt) {
                $lanes[$lane]['lastStartedAt'] = max($lanes[$lane]['lastStartedAt'] ?? $job->lastStartedAt, $job->lastStartedAt);
            }
            if (null !== $job->lastFinishedAt) {
                $lanes[$lane]['lastFinishedAt'] = max($lanes[$lane]['lastFinishedAt'] ?? $job->lastFinishedAt, $job->lastFinishedAt);
            }
            if (null !== $job->lastExitCode && 0 !== $job->lastExitCode) {
                $lanes[$lane]['failedJobs'][] = $job->command;
            }
            if (null !== $job->lastStartedAt && $job->lastStartedAt > ($job->lastFinishedAt ?? 0)) {
                $lanes[$lane]['unfinishedJobs'][] = $job->command;
            }
        }

        return array_values($lanes);
    }
}
