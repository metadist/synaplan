<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;

/**
 * What the payer may pay for. Asked before every call of a run, not only
 * before the turn.
 */
interface ChatRunBudget
{
    /** The monthly budget left and the share one run may spend, read fresh. */
    public function allowance(User $payer): ChatRunAllowance;
}
