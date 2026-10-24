<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use Psr\Clock\ClockInterface;

/**
 * Recovers a research run whose worker disappeared, and closes one that
 * has been waiting too long. It never holds a worker for the wait itself.
 */
final readonly class ResearchReaper
{
    public const ANSWER_WAIT_SECONDS = 600;
    public const PLAN_WAIT_SECONDS = 86400;

    public function __construct(
        private ResearchStateStore $store,
        private ResearchUnitRunner $units,
        private ResearchStageContextFactory $contexts,
        private ResearchUnitBus $bus,
        private ResearchEventLog $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return array{redispatched: int, interrupted: int, continued: int, stopped: int}
     */
    public function reap(): array
    {
        $counts = ['redispatched' => 0, 'interrupted' => 0, 'continued' => 0, 'stopped' => 0];
        foreach ($this->store->expiredClaims() as $claim) {
            $this->reapClaim($claim, $counts);
        }
        foreach ($this->store->activeRuns() as $state) {
            $this->reapWait($state, $counts);
        }

        return $counts;
    }

    /**
     * @param array{runId: string, seq: int, token: string}                            $claim
     * @param array{redispatched: int, interrupted: int, continued: int, stopped: int} $counts
     */
    private function reapClaim(array $claim, array &$counts): void
    {
        $state = $this->store->load($claim['runId']);
        if (null === $state || $state->status->isTerminal() || $state->expectedSeq !== $claim['seq']) {
            $this->store->release($claim['runId'], $claim['seq'], $claim['token']);

            return;
        }
        $attempt = $this->store->noteLostClaim($claim['runId'], $claim['seq']);
        $this->store->release($claim['runId'], $claim['seq'], $claim['token']);
        if ($attempt >= 2) {
            $this->interrupt($state);
            ++$counts['interrupted'];

            return;
        }
        $this->bus->dispatch($claim['runId'], ResearchUnitPlan::resume($state), $claim['seq'], 0);
        ++$counts['redispatched'];
    }

    /**
     * @param array{redispatched: int, interrupted: int, continued: int, stopped: int} $counts
     */
    private function reapWait(ResearchRunState $state, array &$counts): void
    {
        $updated = $state->updatedAt ?? $state->ref->createdAt;
        $age = $this->clock->now()->getTimestamp() - $updated->getTimestamp();
        if (ResearchStatus::WaitingAnswer === $state->status && $age >= self::ANSWER_WAIT_SECONDS) {
            $this->continueAfterQuestion($state);
            ++$counts['continued'];

            return;
        }
        if (ResearchStatus::WaitingPlan === $state->status && $age >= self::PLAN_WAIT_SECONDS) {
            $this->stopWaiting($state);
            ++$counts['stopped'];
        }
    }

    private function continueAfterQuestion(ResearchRunState $state): void
    {
        $state->advance(ResearchStatus::Planning);
        try {
            $this->events->append($state->ref->id, 'question', ['skipped' => true]);
        } catch (ResearchRunNotFound) {
            return;
        }
        $this->store->save($state);
        $this->bus->dispatch($state->ref->id, ResearchUnitPlan::BRIEF_PLAN, $state->expectedSeq, 0);
    }

    private function stopWaiting(ResearchRunState $state): void
    {
        $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_WAITING);
        $state->finish(ResearchStatus::Stopped, $outcome);
        try {
            $this->events->append($state->ref->id, 'stopped', ['outcome' => $outcome->toArray()]);
        } catch (ResearchRunNotFound) {
            return;
        }
        $this->store->save($state);
    }

    /**
     * The lease was lost twice. Write whatever the ledger already holds,
     * once, and keep that report under the interrupted outcome.
     */
    private function interrupt(ResearchRunState $state): void
    {
        $this->writeWhatWasFound($state);
        if ($state->status->isTerminal()) {
            $this->store->save($state);

            return;
        }
        $outcome = new ResearchOutcome(ResearchOutcome::FAILED_INTERRUPTED);
        $state->finish(ResearchStatus::Failed, $outcome);
        try {
            $this->events->append($state->ref->id, 'failed', ['outcome' => $outcome->toArray()]);
        } catch (ResearchRunNotFound) {
            return;
        }
        $this->store->save($state);
    }

    private function writeWhatWasFound(ResearchRunState $state): void
    {
        try {
            $this->units->run($this->contexts->open($state), ResearchUnitPlan::WRITE);
        } catch (RoleUnavailable|ResearchRunNotFound) {
            // The report cannot be written. The run is still closed as interrupted.
        }
    }
}
