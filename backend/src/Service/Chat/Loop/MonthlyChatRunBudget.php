<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\User;
use App\Service\RateLimitService;

/**
 * The payer's monthly cost budget, read fresh before each call. It is the
 * only cost guard of a chat run until the run cost cap exists.
 */
final readonly class MonthlyChatRunBudget implements ChatRunBudget
{
    public function __construct(private RateLimitService $rateLimits)
    {
    }

    public function allows(User $payer): bool
    {
        return true === $this->rateLimits->checkCostBudget($payer)['allowed'];
    }
}
