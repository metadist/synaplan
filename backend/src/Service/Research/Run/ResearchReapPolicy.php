<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Run\RunReapPolicy;
use App\Service\Run\RunState;
use Psr\Log\LoggerInterface;

/**
 * What the run reaper may not decide alone for research: how long a run
 * waits for an answer or a plan, which step resumes it, and how it ends
 * when its step was lost twice. A run it closes still reaches its chat.
 */
final readonly class ResearchReapPolicy implements RunReapPolicy
{
    /** An open question is skipped after this long, so the run carries on without the answer. */
    public const ANSWER_WAIT_SECONDS = 600;

    /** A plan nobody approved ends the run after this long. */
    public const PLAN_WAIT_SECONDS = 86400;

    /** The first interrupt writes the report; a later one only closes the run, so it is paid for once. */
    private const WRITING_ATTEMPT = 2;

    public function __construct(
        private ResearchStateStore $store,
        private ResearchUnitRunner $units,
        private ResearchStageContextFactory $contexts,
        private ResearchUnitBus $bus,
        private ResearchEventLog $events,
        private LoggerInterface $logger,
        private ResearchReportPoster $reports,
        private ?ResearchSavedTaskSignal $savedTasks = null,
    ) {
    }

    public function isWaiting(RunState $state): bool
    {
        return ResearchReply::isWaiting(self::research($state));
    }

    public function reapWait(RunState $state, int $ageSeconds): bool
    {
        $state = self::research($state);
        if ($this->store->writeNowRequested($state->ref->id)) {
            return $this->writeAfterWait($state);
        }
        if (ResearchStatus::WaitingAnswer === $state->status) {
            return $ageSeconds >= self::ANSWER_WAIT_SECONDS && $this->continueAfterQuestion($state);
        }
        if (ResearchStatus::WaitingPlan === $state->status) {
            return $ageSeconds >= self::PLAN_WAIT_SECONDS && $this->stopWaiting($state);
        }

        return false;
    }

    public function resumeUnit(RunState $state): string
    {
        return ResearchUnitPlan::resume(self::research($state));
    }

    /**
     * The step was lost twice. The first time, write whatever the ledger
     * already holds and keep that report under the interrupted outcome.
     */
    public function interrupt(RunState $fresh, int $attempt): bool
    {
        $fresh = self::research($fresh);
        if (self::WRITING_ATTEMPT === $attempt && null === $fresh->ledger->report()) {
            $this->writeWhatWasFound($fresh);
        }
        if ($fresh->status->isTerminal()) {
            return true;
        }
        $outcome = new ResearchOutcome(ResearchOutcome::FAILED_INTERRUPTED);
        $fresh->finish(ResearchStatus::Failed, $outcome);

        return $this->record($fresh, 'failed', ['outcome' => $outcome->toArray()]);
    }

    public function closed(RunState $state): void
    {
        $state = self::research($state);
        $this->reports->deliver($state);
        $this->savedTasks?->after($state);
    }

    private function continueAfterQuestion(ResearchRunState $state): bool
    {
        $unit = ResearchUnitPlan::BRIEF_PLAN;
        $saved = $this->underClaim($state, function (ResearchRunState $fresh) use (&$unit): bool {
            if (ResearchStatus::WaitingAnswer !== $fresh->status) {
                return false;
            }
            $unit = ResearchReply::skipAll($fresh);

            return $this->record($fresh, 'question', ['skipped' => true]);
        });
        if (null === $saved) {
            return false;
        }
        $this->bus->dispatch($saved->ref->id, $unit, $saved->expectedSeq, 0);

        return true;
    }

    /** Write now arrived while the step that opened this wait was still underway. */
    private function writeAfterWait(ResearchRunState $state): bool
    {
        $saved = $this->underClaim($state, static fn (ResearchRunState $fresh): bool => ResearchReply::writeNow($fresh));
        if (null === $saved) {
            return false;
        }
        $this->bus->dispatch($saved->ref->id, ResearchUnitPlan::WRITE, $saved->expectedSeq, 0);

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
     * @param \Closure(ResearchRunState): bool $change false leaves the run as it is
     */
    private function underClaim(ResearchRunState $state, \Closure $change): ?ResearchRunState
    {
        $saved = $this->store->underClaim($state, ResearchUnitWorker::LEASE_MS, $change);
        if (null !== $saved && $saved->status->isTerminal()) {
            $this->closed($saved);
        }

        return $saved;
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

    private static function research(RunState $state): ResearchRunState
    {
        if (!$state instanceof ResearchRunState) {
            throw new \LogicException('The research reap policy reaps research runs only.');
        }

        return $state;
    }
}
