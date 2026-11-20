<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;

/**
 * Whether the payer may pay for the next model call. Asked before every
 * call of a run, not only before the turn.
 */
interface ChatRunBudget
{
    public function allows(User $payer): bool;
}
