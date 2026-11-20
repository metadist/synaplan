<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Model;
use App\Entity\User;
use App\Service\RateLimitService;

/**
 * Whether a long answer may start at all (03 §1.3): what the run may spend
 * must cover its first call and the reserve for the wrap-up. Otherwise the
 * turn answers without tools and says the monthly budget is nearly used,
 * instead of starting a run that could only stop at once.
 *
 * Before the first call only the model's price is known, so the estimate
 * comes from that. A model without a price is never held back here.
 */
final readonly class ChatRunStartBudget
{
    public function __construct(
        private ChatRunBudget $budget,
        private RateLimitService $rates,
        private ChatRunLoopLimits $limits,
    ) {
    }

    /**
     * @param list<array<string, mixed>> $messages the first call's messages
     */
    public function affords(User $payer, ?Model $model, array $messages): bool
    {
        $allowance = $this->budget->allowance($payer);
        if (!$allowance->allowed) {
            return false;
        }
        $limit = ChatRunCostGuard::room(0, $allowance);
        $rates = $this->rates($model);
        if (null === $limit || null === $rates) {
            return true;
        }
        $call = $rates->call(ChatRunCostEstimate::chars($messages));

        return $call + (new ChatRunCostGuard($this->budget, $this->limits))->reserve($call, $limit) < $limit;
    }

    /** The model's price as the person is billed for it, or null without one. */
    public function rates(?Model $model): ?ChatRunRates
    {
        return ChatRunRates::of($model, $this->rates->getMarkupPercent());
    }
}
