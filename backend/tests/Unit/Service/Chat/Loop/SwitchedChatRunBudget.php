<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\User;
use App\Service\Chat\Loop\ChatRunBudget;

/** A budget a test empties between two calls. */
final class SwitchedChatRunBudget implements ChatRunBudget
{
    public int $checks = 0;

    /** The checks that may still say yes; past them the budget is empty. */
    public int $yes = \PHP_INT_MAX;

    public function __construct(public bool $left = true)
    {
    }

    public function allows(User $user): bool
    {
        ++$this->checks;

        return $this->left && $this->checks <= $this->yes;
    }
}
