<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\StageResult;

/**
 * Claims one research step, runs it, and queues the next.
 *
 * A second delivery of the same step finds the claim taken and returns
 * before any paid call. The start endpoint queues the first step.
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
        private ResearchConfig $config,
        private ResearchReportPoster $reports,
        private ?ResearchSavedTaskSignal $savedTasks = null,
    ) {
    }

    public function handle(string $runId, string $unit, int $seq): void
    {
        if (!ResearchUnitPlan::known($unit) || !ResearchIds::isValid($runId) || $seq < 1) {
            return;
        }
        $state = $this->store->load($runId);
        if (!self::due($state, $seq)) {
            return;
        }
        $token = $this->store->claim($runId, $seq, self::leaseMs($state, $unit));
        if (null === $token) {
            return;
        }
        // A duplicate delivery can load before the first one checkpoints and
        // claim right after it. Only the state read under the claim counts.
        $state = $this->store->load($runId);
        if (!self::due($state, $seq)) {
            $this->store->release($runId, $seq, $token);

            return;
        }
        $result = $this->runClaimed($state, $unit, $token);
        if (!$this->store->checkpoint($state, $token)) {
            return;
        }
        $this->after($state, $unit, $result);
    }

    /** @phpstan-assert-if-true ResearchRunState $state */
    private static function due(?ResearchRunState $state, int $seq): bool
    {
        return null !== $state && !$state->status->isTerminal() && $state->expectedSeq === $seq;
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
        if ($this->store->stopRequested($state->ref->id)) {
            return $this->stop($state);
        }
        if (!$this->config->isEnabled()) {
            return $this->stoppedByAdmin($state);
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
        $this->deliverReport($state);
        if ($state->status->isTerminal()) {
            return;
        }
        $next = ResearchUnitPlan::nextUnit($unit, $result->next);
        if (null === $next) {
            return;
        }
        $this->bus->dispatch($state->ref->id, $next, $state->expectedSeq, self::GAP_MS);
    }

    private function stoppedByAdmin(ResearchRunState $state): StageResult
    {
        if (null === $state->ledger->report()) {
            try {
                $this->units->run($this->contexts->open($state), ResearchUnitPlan::WRITE);
            } catch (RoleUnavailable) {
                // What was already found is the report. The missing model is not a second failure.
            }
        }
        if ($state->status->isTerminal()) {
            return new StageResult('done');
        }
        $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_BY_ADMIN);
        $state->finish(ResearchStatus::Stopped, $outcome);
        $this->record($state, 'stopped', $outcome);

        return new StageResult('done');
    }

    private function deliverReport(ResearchRunState $state): void
    {
        if ($state->status->isTerminal()) {
            $this->reports->deliver($state);
            $this->savedTasks?->after($state);
        }
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
