<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Loop;

use App\Service\Research\Run\Ledger\EvidenceLedger;
use App\Service\Research\Run\Ledger\PlanPoint;
use App\Service\Research\Run\ResearchBudget;
use App\Service\Research\Run\ResearchOutcome;

/**
 * The five stop rules, in the order the plan fixes. A search budget that
 * is spent does not end the loop while sources can still be read, and the
 * other way round. Both spent, the round cap, the clock, the cost cap and
 * the call cap do.
 *
 * The cost cap here is this run's limit for its depth, so it ends as
 * `stopped_limit`. `stopped_budget` says the person's monthly budget is used
 * up and belongs to the monthly check only.
 */
final readonly class LoopRules
{
    public function judge(
        EvidenceLedger $ledger,
        ResearchBudget $budget,
        bool $stoppedByUser,
        bool $writeNow,
        ?string $finish,
        bool $madeProgress,
        bool $hintGiven,
    ): LoopStop {
        if ($stoppedByUser) {
            return $this->halt('user', ResearchOutcome::STOPPED_BY_USER);
        }
        if ($writeNow) {
            return new LoopStop(LoopStop::WRITE, 'write_now', null, false);
        }
        if (null !== $finish) {
            return new LoopStop(LoopStop::WRITE, 'finish', null, false);
        }
        if ($this->covered($ledger)) {
            return new LoopStop(LoopStop::WRITE, 'answered', null, false);
        }

        $budgetStop = $this->budget($ledger, $budget);
        if (null !== $budgetStop) {
            return $budgetStop;
        }
        if (!$madeProgress && $hintGiven) {
            return $this->write('no_progress', ResearchOutcome::COMPLETE_PARTIAL, $ledger);
        }
        if (!$madeProgress) {
            return new LoopStop(LoopStop::CONTINUE, 'no_progress', null, true);
        }

        return new LoopStop(LoopStop::CONTINUE, null, null, false);
    }

    private function budget(EvidenceLedger $ledger, ResearchBudget $budget): ?LoopStop
    {
        if (0 === $budget->callsLeft()) {
            return $this->write('calls', ResearchOutcome::COMPLETE_PARTIAL, $ledger);
        }
        if (0 === $budget->secondsLeft()) {
            return $this->write('wall', ResearchOutcome::STOPPED_LIMIT, $ledger);
        }
        if (!$budget->canCall()) {
            return $this->write('cost', ResearchOutcome::STOPPED_LIMIT, $ledger);
        }
        if (!$budget->canRound()) {
            return $this->write('rounds', ResearchOutcome::STOPPED_LIMIT, $ledger);
        }
        if (!$budget->canSearch() && !$budget->canRead()) {
            return $this->write('gather', ResearchOutcome::STOPPED_LIMIT, $ledger);
        }

        return null;
    }

    private function covered(EvidenceLedger $ledger): bool
    {
        $points = $ledger->points();
        if ([] === $points) {
            return false;
        }
        foreach ($points as $point) {
            if (PlanPoint::ANSWERED !== $point->status && PlanPoint::SKIPPED !== $point->status) {
                return false;
            }
        }

        return true;
    }

    private function write(string $reason, string $code, EvidenceLedger $ledger): LoopStop
    {
        $points = count($ledger->points());
        $covered = 0;
        foreach ($ledger->points() as $point) {
            if (PlanPoint::ANSWERED === $point->status) {
                ++$covered;
            }
        }

        return new LoopStop(LoopStop::WRITE, $reason, new ResearchOutcome($code, [
            'open' => $points - $covered,
            'points' => $points,
            'covered' => $covered,
        ]), false);
    }

    private function halt(string $reason, string $code): LoopStop
    {
        return new LoopStop(LoopStop::HALT, $reason, new ResearchOutcome($code), false);
    }
}
