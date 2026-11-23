<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;

/**
 * The payer's monthly cost budget and the share of it one run may spend,
 * read fresh before each call.
 */
final readonly class MonthlyChatRunBudget implements ChatRunBudget
{
    public function __construct(private ChatRunCostLimit $limit)
    {
    }

    public function allowance(User $payer): ChatRunAllowance
    {
        return $this->limit->allowance($payer);
    }
}
