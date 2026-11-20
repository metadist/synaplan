<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;

/**
 * The cost limit of a run (03 §1.3), read fresh before every model call.
 *
 * The run may spend the share of the monthly budget the person chose; the
 * payer may spend what is left of that budget. Each limit holds back a
 * reserve, the larger of a share of the run's limit and the dearest call
 * so far, so the wrap-up call still fits. When a limit is reached the run
 * wraps up instead of making another working call: it may go past a limit
 * by at most that one call.
 *
 * A model without a price costs nothing here; circle detection ends a
 * stuck run of such a model (owner decision: no token limit).
 */
final readonly class ChatRunCostGuard
{
    public function __construct(
        private ChatRunBudget $budget,
        private ChatRunLoopLimits $limits,
    ) {
    }

    /** The reason to wrap up now, or null while the run may go on. */
    public function check(ChatRunState $state, User $payer): ?ChatRunEnd
    {
        $allowance = $this->budget->allowance($payer);
        $runCap = $allowance->runCapMicro;
        $reserve = max(
            null === $runCap ? 0 : intdiv($runCap * $this->limits->reservePercent(), 100),
            $state->dearestMicro,
        );
        if (!$allowance->allowed || (null !== $allowance->leftMicro && $allowance->leftMicro <= $reserve)) {
            return ChatRunEnd::PayerEmpty;
        }
        if (null !== $runCap && $state->costMicro + $reserve >= $runCap) {
            return ChatRunEnd::CostLimit;
        }

        return null;
    }
}
