<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Research\Run\Stage\StageResult;
use App\Service\Run\RunClaim;
use App\Service\Run\RunNotFound;
use App\Service\Run\RunState;
use App\Service\Run\RunUnitHandler;
use App\Service\Run\RunUnitResult;

/**
 * The research steps as units of the run kernel: what a step runs, how long
 * its lease is, how a stop ends it, and what happens once it is written.
 */
final readonly class ResearchUnitHandler implements RunUnitHandler
{
    public function __construct(
        private ResearchStateStore $store,
        private ResearchUnitRunner $units,
        private ResearchStageContextFactory $contexts,
        private ResearchEventLog $events,
        private ResearchConfig $config,
        private ResearchReportPoster $reports,
        private ?ResearchSavedTaskSignal $savedTasks = null,
    ) {
    }

    public function knows(string $unit): bool
    {
        return ResearchUnitPlan::known($unit);
    }

    /**
     * A round searches and reads until the wall clock is up, so its lease
     * covers the time the run has left plus one slow call.
     */
    public function leaseMs(RunState $state, string $unit): int
    {
        $state = self::research($state);
        if (ResearchUnitPlan::ROUND !== $unit) {
            return ResearchUnitWorker::LEASE_MS;
        }

        return $state->budget->secondsLeft() * 1000 + ResearchUnitWorker::LEASE_MS;
    }

    public function run(RunState $state, string $unit, RunClaim $claim): RunUnitResult
    {
        $state = self::research($state);
        try {
            $result = $this->step($state, $unit, $claim);
        } catch (ResearchRunNotFound $gone) {
            throw new RunNotFound($gone->runId);
        }

        return new RunUnitResult(ResearchUnitPlan::nextUnit($unit, $result->next));
    }

    public function cancel(RunState $state): RunUnitResult
    {
        $state = self::research($state);
        $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER);
        $state->finish(ResearchStatus::Stopped, $outcome);
        $this->record($state, 'stopped', $outcome);

        return RunUnitResult::done();
    }

    public function after(RunState $state, string $unit, RunUnitResult $result): void
    {
        $state = self::research($state);
        if (ResearchUnitPlan::DELIVER === $unit && null !== $state->ledger->report()) {
            $this->store->dropSourceTexts($state->ref->id);
        }
        if ($state->status->isTerminal()) {
            $this->reports->deliver($state);
            $this->savedTasks?->after($state);
        }
    }

    private function step(ResearchRunState $state, string $unit, RunClaim $claim): StageResult
    {
        if (!$this->config->isEnabled()) {
            return $this->stoppedByAdmin($state, $claim);
        }
        try {
            $context = $this->contexts->open($state);
        } catch (RoleUnavailable $missing) {
            return $this->failRole($state, $missing);
        }
        $context->lease = $claim;

        return $this->units->run($context, $unit);
    }

    private function stoppedByAdmin(ResearchRunState $state, RunClaim $claim): StageResult
    {
        if (null === $state->ledger->report()) {
            try {
                $context = $this->contexts->open($state);
                $context->lease = $claim;
                $this->units->run($context, ResearchUnitPlan::WRITE);
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

    private static function research(RunState $state): ResearchRunState
    {
        if (!$state instanceof ResearchRunState) {
            throw new \LogicException('The research handler runs research runs only.');
        }

        return $state;
    }
}
