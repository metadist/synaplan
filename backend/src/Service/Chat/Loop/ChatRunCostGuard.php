<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;

/**
 * The cost limit of a run (03 §1.3), read fresh before every model call.
 *
 * The run may spend the smaller of two amounts: the share of the monthly
 * budget the person chose, and what is left of that budget now. It holds
 * back a reserve for the wrap-up call: the estimated cost of that call
 * ({@see ChatRunCostEstimate}), at least a tenth of what the run may spend.
 * A larger share can therefore never stop a run earlier than a smaller one.
 *
 * Past 80 % the model is told once to finish up. When spent plus reserve
 * reach the limit the run wraps up instead of making another working call:
 * it may go past the limit by at most that one call. A budget already used
 * up (another chat spent it meanwhile) pays for nothing more: the run ends
 * at once.
 *
 * A model without a price costs nothing here; circle detection ends a
 * stuck run of such a model (owner decision: no token limit).
 */
final readonly class ChatRunCostGuard
{
    /** Said once, when spent plus reserve pass {@see SOFT_PERCENT} of the limit. */
    public const NEARLY_USED = 'The budget for this answer is nearly used. Finish up now: work with what you already have and write the answer.';

    private const SOFT_PERCENT = 80;

    public function __construct(
        private ChatRunBudget $budget,
        private ChatRunLoopLimits $limits,
    ) {
    }

    public function check(ChatRunState $state, User $payer): ChatRunCostCheck
    {
        $allowance = $this->budget->allowance($payer);
        if (!$allowance->allowed) {
            return new ChatRunCostCheck(ChatRunEnd::BudgetUsed);
        }
        $room = self::room($state->costMicro, $allowance);
        if (null === $room) {
            return new ChatRunCostCheck(null);
        }
        $limit = $state->costMicro + $room;
        $reserve = $this->reserve(ChatRunCostEstimate::wrapUp($state), $limit);
        $planned = $state->costMicro + $reserve;
        if ($planned >= $limit) {
            return new ChatRunCostCheck(self::payerBinds($state->costMicro, $allowance) ? ChatRunEnd::PayerEmpty : ChatRunEnd::CostLimit, $limit, true);
        }

        return new ChatRunCostCheck(null, $limit, $planned * 100 >= $limit * self::SOFT_PERCENT);
    }

    /** The reserve for the wrap-up: its estimate, at least the configured share of the limit. */
    public function reserve(int $wrapUpMicro, int $limitMicro): int
    {
        return max($wrapUpMicro, intdiv(max(0, $limitMicro) * $this->limits->reservePercent(), 100));
    }

    /**
     * What the run may still spend: the smaller of its share and what the
     * payer has left; null when neither limits it.
     */
    public static function room(int $spentMicro, ChatRunAllowance $allowance): ?int
    {
        $share = null === $allowance->runCapMicro ? null : $allowance->runCapMicro - $spentMicro;
        $left = $allowance->leftMicro;
        if (null === $share && null === $left) {
            return null;
        }

        return max(0, min($share ?? \PHP_INT_MAX, $left ?? \PHP_INT_MAX));
    }

    /** The line the model gets with each working call while a limit applies. */
    public static function line(int $spentMicro, int $limitMicro): string
    {
        return 'Budget for this answer: '.self::dollars($limitMicro).', used '.self::dollars($spentMicro)
            .' ('.self::dollars(max(0, $limitMicro - $spentMicro)).' left). Plan your remaining steps to finish within it.';
    }

    private static function payerBinds(int $spentMicro, ChatRunAllowance $allowance): bool
    {
        return null !== $allowance->leftMicro
            && (null === $allowance->runCapMicro || $allowance->leftMicro < $allowance->runCapMicro - $spentMicro);
    }

    private static function dollars(int $micro): string
    {
        return '$'.number_format($micro / 1_000_000, $micro > 0 && $micro < 10_000 ? 4 : 2, '.', '');
    }
}
