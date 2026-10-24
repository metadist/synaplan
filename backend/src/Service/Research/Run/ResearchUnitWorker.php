<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\StageResult;

/**
 * Claims one research step, runs it, and queues the next.
 *
 * A second delivery of the same step finds the claim taken and returns
 * before any paid call. The first step is queued by the start endpoint,
 * which is a later piece; this worker only continues a run that exists.
 */
final readonly class ResearchUnitWorker
{
    /** Two minutes of work, and the lease is twice that so a slow call can still checkpoint. */
    public const LEASE_MS = 240_000;

    /** Gap before the next step, so one run cannot fill the queue. */
    public const GAP_MS = 3_000;

    public function __construct(
        private ResearchStateStore $store,
        private ResearchUnitRunner $units,
        private ResearchStageContextFactory $contexts,
        private ResearchUnitBus $bus,
        private ResearchEventLog $events,
    ) {
    }

    public function handle(string $runId, string $unit, int $seq): void
    {
        if (!ResearchUnitPlan::known($unit)) {
            return;
        }
        $state = $this->store->load($runId);
        if (null === $state || $state->status->isTerminal() || $state->expectedSeq !== $seq) {
            return;
        }
        $token = $this->store->claim($runId, $seq, self::leaseMs($state, $unit));
        if (null === $token) {
            return;
        }
        $result = $this->runClaimed($state, $unit, $token);
        if (!$this->store->checkpoint($state, $token)) {
            return;
        }
        $this->after($state, $unit, $result);
    }

    /**
     * A round searches and reads until the wall clock is up, so its lease
     * covers the time the run has left plus one slow call.
     */
    private static function leaseMs(ResearchRunState $state, string $unit): int
    {
        if (ResearchUnitPlan::ROUND !== $unit) {
            return self::LEASE_MS;
        }

        return $state->budget->secondsLeft() * 1000 + self::LEASE_MS;
    }

    private function runClaimed(ResearchRunState $state, string $unit, string $token): StageResult
    {
        try {
            return $this->step($state, $unit);
        } catch (ResearchRunNotFound) {
            $this->store->release($state->ref->id, $state->expectedSeq, $token);

            return new StageResult('done');
        }
    }

    private function step(ResearchRunState $state, string $unit): StageResult
    {
        if ($state->stopRequested) {
            return $this->stop($state);
        }
        try {
            $context = $this->contexts->open($state);
        } catch (RoleUnavailable $missing) {
            return $this->failRole($state, $missing);
        }

        return $this->units->run($context, $unit);
    }

    private function after(ResearchRunState $state, string $unit, StageResult $result): void
    {
        if (ResearchUnitPlan::DELIVER === $unit && null !== $state->ledger->report()) {
            $this->store->dropSourceTexts($state->ref->id);
        }
        if ($state->status->isTerminal()) {
            return;
        }
        $next = ResearchUnitPlan::nextUnit($unit, $result->next);
        if (null === $next) {
            return;
        }
        $this->bus->dispatch($state->ref->id, $next, $state->expectedSeq, self::GAP_MS);
    }

    private function stop(ResearchRunState $state): StageResult
    {
        $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER);
        $state->finish(ResearchStatus::Stopped, $outcome);
        $this->record($state, 'stopped', $outcome);

        return new StageResult('done');
    }

    private function failRole(ResearchRunState $state, RoleUnavailable $missing): StageResult
    {
        $outcome = new ResearchOutcome(ResearchOutcome::FAILED_ROLE_UNAVAILABLE, ['role' => $missing->role]);
        $state->finish(ResearchStatus::Failed, $outcome);
        $this->record($state, 'failed', $outcome);

        return new StageResult('done');
    }

    private function record(ResearchRunState $state, string $event, ResearchOutcome $outcome): void
    {
        try {
            $this->events->append($state->ref->id, $event, ['outcome' => $outcome->toArray()]);
        } catch (ResearchRunNotFound) {
            // The run is already gone, so there is nothing left to record.
        }
    }
}
