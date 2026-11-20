<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\User;
use App\Service\Chat\Loop\ChatRunBudget;

/** A budget a test empties between two calls. */
final class SwitchedChatRunBudget implements ChatRunBudget
{
    public int $checks = 0;

    public function __construct(public bool $left = true)
    {
    }

    public function allows(User $user): bool
    {
        ++$this->checks;

        return $this->left;
    }
}
