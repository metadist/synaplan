<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Run\RunUnit;

/**
 * Claims one research step, runs it, and queues the next, on the run kernel.
 *
 * A second delivery of the same step finds the claim taken and returns
 * before any paid call. The start endpoint queues the first step.
 */
final readonly class ResearchUnitWorker
{
    /**
     * Four minutes. A live step renews this same lease while it runs.
     * A longer constant is not how a slow call keeps its claim.
     */
    public const LEASE_MS = 240_000;

    /** Gap before the next step, so one run cannot fill the queue. */
    public const GAP_MS = RunUnit::GAP_MS;

    private RunUnit $unit;

    public function __construct(
        ResearchStateStore $store,
        ResearchUnitRunner $units,
        ResearchStageContextFactory $contexts,
        ResearchUnitBus $bus,
        ResearchEventLog $events,
        ResearchConfig $config,
        ResearchReportPoster $reports,
        ?ResearchSavedTaskSignal $savedTasks = null,
    ) {
        $this->unit = new RunUnit(
            $store->runs(),
            new ResearchUnitHandler($store, $units, $contexts, $events, $config, $reports, $savedTasks),
            $bus,
        );
    }

    public function handle(string $runId, string $unit, int $seq): void
    {
        $this->unit->handle($runId, $unit, $seq);
    }
}
