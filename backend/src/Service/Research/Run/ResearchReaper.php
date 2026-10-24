<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Recovers a research run whose worker disappeared, and closes one that
 * has been waiting too long. It never holds a worker for the wait itself.
 *
 * Every write takes the unit's claim and goes through `checkpoint`, so a
 * worker or an answer that arrives at the same moment cannot be overwritten.
 */
final readonly class ResearchReaper
{
    public const ANSWER_WAIT_SECONDS = 600;
    public const PLAN_WAIT_SECONDS = 86400;
    public const STALL_SECONDS = 600;

    /** The first interrupt writes the report; a later one only closes the run, so it is paid for once. */
    private const WRITING_ATTEMPT = 2;

    public function __construct(
        private ResearchStateStore $store,
        private ResearchUnitRunner $units,
        private ResearchStageContextFactory $contexts,
        private ResearchUnitBus $bus,
        private ResearchEventLog $events,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{redispatched: int, interrupted: int, continued: int, stopped: int}
     */
    public function reap(): array
    {
        $counts = ['redispatched' => 0, 'interrupted' => 0, 'continued' => 0, 'stopped' => 0];
        foreach ($this->store->expiredClaims() as $claim) {
            $this->guarded($claim['runId'], function () use ($claim, &$counts): void {
                $this->reapClaim($claim, $counts);
            });
        }
        foreach ($this->store->activeRuns() as $state) {
            $this->guarded($state->ref->id, function () use ($state, &$counts): void {
                $this->reapWait($state, $counts);
            });
        }

        return $counts;
    }

    /** One broken run must not keep every other run from being recovered. */
    private function guarded(string $runId, \Closure $work): void
    {
        try {
            $work();
        } catch (\Throwable $e) {
            $this->logger->error('Research reaper could not recover run {run}: {error}', [
                'run' => $runId,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * @param array{runId: string, seq: int, token: string}                            $claim
     * @param array{redispatched: int, interrupted: int, continued: int, stopped: int} $counts
     */
    private function reapClaim(array $claim, array &$counts): void
    {
        $state = $this->store->load($claim['runId']);
        if (null === $state || $state->status->isTerminal() || $state->expectedSeq !== $claim['seq'] || self::isWaiting($state)) {
            $this->store->release($claim['runId'], $claim['seq'], $claim['token']);

            return;
        }
        $attempt = $this->store->noteLostClaim($claim['runId'], $claim['seq']);
        if ($attempt >= self::WRITING_ATTEMPT) {
            if ($this->interrupt($state, $attempt)) {
                $this->store->release($claim['runId'], $claim['seq'], $claim['token']);
                ++$counts['interrupted'];
            }

            return;
        }
        $this->bus->dispatch($claim['runId'], ResearchUnitPlan::resume($state), $claim['seq'], 0);
        $this->store->release($claim['runId'], $claim['seq'], $claim['token']);
        ++$counts['redispatched'];
    }

    /**
     * @param array{redispatched: int, interrupted: int, continued: int, stopped: int} $counts
     */
    private function reapWait(ResearchRunState $state, array &$counts): void
    {
        $now = $this->clock->now()->getTimestamp();
        $age = $now - ($state->updatedAt ?? $state->ref->createdAt)->getTimestamp();
        if (ResearchStatus::WaitingAnswer === $state->status) {
            if ($age >= self::ANSWER_WAIT_SECONDS && $this->continueAfterQuestion($state)) {
                ++$counts['continued'];
            }

            return;
        }
        if (ResearchStatus::WaitingPlan === $state->status) {
            if ($age >= self::PLAN_WAIT_SECONDS && $this->stopWaiting($state)) {
                ++$counts['stopped'];
            }

            return;
        }
        if (!$this->isStalled($state, $now)) {
            return;
        }
        if ($this->store->lostClaims($state->ref->id, $state->expectedSeq) > 0) {
            $attempt = $this->store->noteLostClaim($state->ref->id, $state->expectedSeq);
            if ($this->interrupt($state, $attempt)) {
                ++$counts['interrupted'];
            }

            return;
        }
        $this->bus->dispatch($state->ref->id, ResearchUnitPlan::resume($state), $state->expectedSeq, 0);
        $this->store->noteLostClaim($state->ref->id, $state->expectedSeq);
        ++$counts['redispatched'];
    }

    /**
     * A step finished but its successor never started: nobody holds the
     * claim, and neither the last checkpoint nor the last redispatch is
     * recent. A second stall closes the run instead of leaving it "running".
     */
    private function isStalled(ResearchRunState $state, int $now): bool
    {
        $since = max(
            ($state->updatedAt ?? $state->ref->createdAt)->getTimestamp(),
            $this->store->lastRedispatchAt($state->ref->id, $state->expectedSeq) ?? 0,
        );

        return $now - $since >= self::STALL_SECONDS
            && !$this->store->isClaimed($state->ref->id, $state->expectedSeq);
    }

    private function continueAfterQuestion(ResearchRunState $state): bool
    {
        $saved = $this->underClaim($state, function (ResearchRunState $fresh): bool {
            if (ResearchStatus::WaitingAnswer !== $fresh->status) {
                return false;
            }
            $fresh->advance(ResearchStatus::Planning);

            return $this->record($fresh, 'question', ['skipped' => true]);
        });
        if (null === $saved) {
            return false;
        }
        $this->bus->dispatch($saved->ref->id, ResearchUnitPlan::BRIEF_PLAN, $saved->expectedSeq, 0);

        return true;
    }

    private function stopWaiting(ResearchRunState $state): bool
    {
        return null !== $this->underClaim($state, function (ResearchRunState $fresh): bool {
            if (ResearchStatus::WaitingPlan !== $fresh->status) {
                return false;
            }
            $outcome = new ResearchOutcome(ResearchOutcome::STOPPED_WAITING);
            $fresh->finish(ResearchStatus::Stopped, $outcome);

            return $this->record($fresh, 'stopped', ['outcome' => $outcome->toArray()]);
        });
    }

    /**
     * The unit was lost twice. The first time, write whatever the ledger
     * already holds and keep that report under the interrupted outcome.
     */
    private function interrupt(ResearchRunState $state, int $attempt): bool
    {
        return null !== $this->underClaim($state, function (ResearchRunState $fresh) use ($attempt): bool {
            if (self::WRITING_ATTEMPT === $attempt && null === $fresh->ledger->report()) {
                $this->writeWhatWasFound($fresh);
            }
            if ($fresh->status->isTerminal()) {
                return true;
            }
            $outcome = new ResearchOutcome(ResearchOutcome::FAILED_INTERRUPTED);
            $fresh->finish(ResearchStatus::Failed, $outcome);

            return $this->record($fresh, 'failed', ['outcome' => $outcome->toArray()]);
        });
    }

    private function writeWhatWasFound(ResearchRunState $state): void
    {
        try {
            $this->units->run($this->contexts->open($state), ResearchUnitPlan::WRITE);
        } catch (RoleUnavailable|ResearchRunNotFound) {
            // The report cannot be written. The run is still closed as interrupted.
        } catch (\Throwable $e) {
            $this->logger->warning('Research reaper could not write the report of run {run}: {error}', [
                'run' => $state->ref->id,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }

    /**
     * Take the claim of the unit the run is waiting on, change the stored
     * run, and checkpoint it. Null when someone else holds the claim, the
     * run moved on meanwhile, or the change was refused.
     *
     * @param \Closure(ResearchRunState): bool $change false leaves the run as it is
     */
    private function underClaim(ResearchRunState $state, \Closure $change): ?ResearchRunState
    {
        $runId = $state->ref->id;
        $seq = $state->expectedSeq;
        $token = $this->store->claim($runId, $seq, ResearchUnitWorker::LEASE_MS);
        if (null === $token) {
            return null;
        }
        try {
            $fresh = $this->store->load($runId);
            if (null === $fresh || $fresh->status->isTerminal() || $fresh->expectedSeq !== $seq || !$change($fresh)) {
                $this->store->release($runId, $seq, $token);

                return null;
            }
        } catch (\Throwable $e) {
            $this->store->release($runId, $seq, $token);

            throw $e;
        }

        return $this->store->checkpoint($fresh, $token) ? $fresh : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function record(ResearchRunState $state, string $event, array $data): bool
    {
        try {
            $this->events->append($state->ref->id, $event, $data);
        } catch (ResearchRunNotFound) {
            return false;
        }

        return true;
    }

    private static function isWaiting(ResearchRunState $state): bool
    {
        return ResearchStatus::WaitingAnswer === $state->status || ResearchStatus::WaitingPlan === $state->status;
    }
}
