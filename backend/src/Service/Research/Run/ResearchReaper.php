<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Run\RunReaper;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Recovers a research run whose worker disappeared, and closes one that
 * has been waiting too long, on the run kernel. It never holds a worker for
 * the wait itself.
 */
final readonly class ResearchReaper
{
    public const ANSWER_WAIT_SECONDS = 600;
    public const PLAN_WAIT_SECONDS = 86400;
    public const STALL_SECONDS = RunReaper::STALL_SECONDS;

    private RunReaper $reaper;

    public function __construct(
        ResearchStateStore $store,
        ResearchUnitRunner $units,
        ResearchStageContextFactory $contexts,
        ResearchUnitBus $bus,
        ResearchEventLog $events,
        ClockInterface $clock,
        LoggerInterface $logger,
        ResearchReportPoster $reports,
        ?ResearchSavedTaskSignal $savedTasks = null,
    ) {
        $this->reaper = new RunReaper(
            $store->runs(),
            new ResearchReapPolicy($store, $units, $contexts, $bus, $events, $logger, $reports, $savedTasks),
            $bus,
            $clock,
            $logger,
            ResearchUnitWorker::LEASE_MS,
        );
    }

    /**
     * @return array{redispatched: int, interrupted: int, waited: int}
     */
    public function reap(): array
    {
        return $this->reaper->reap();
    }
}
