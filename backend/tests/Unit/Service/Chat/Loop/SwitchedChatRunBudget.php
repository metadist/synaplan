<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\User;
use App\Service\Chat\Loop\ChatRunAllowance;
use App\Service\Chat\Loop\ChatRunBudget;

/**
 * A payer's budget a test sets: emptied between two calls, or with a share
 * per run and what is left of the month. Amounts are micro-dollars; null is
 * a plan without a monthly budget.
 */
final class SwitchedChatRunBudget implements ChatRunBudget
{
    public int $checks = 0;

    /** The checks that may still say yes; past them the budget is empty. */
    public int $yes = \PHP_INT_MAX;

    public ?int $runCapMicro = null;
    public ?int $leftMicro = null;

    /** What the payer spent since the test set leftMicro; the left amount shrinks by it. */
    public ?\Closure $spent = null;

    public function __construct(public bool $left = true)
    {
    }

    public function allowance(User $payer): ChatRunAllowance
    {
        ++$this->checks;
        $allowed = $this->left && $this->checks <= $this->yes;

        $left = null === $this->leftMicro ? null : $this->leftMicro - (null === $this->spent ? 0 : (int) ($this->spent)());

        return new ChatRunAllowance($allowed, $allowed ? $left : 0, $this->runCapMicro);
    }
}
