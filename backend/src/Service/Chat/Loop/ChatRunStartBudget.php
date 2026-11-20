<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Model;
use App\Entity\User;
use App\Service\RateLimitService;

/**
 * Whether a long answer may start at all (03 §1.3): what the run may spend
 * must cover its first call and the reserve for the wrap-up. Otherwise the
 * turn answers without tools and says which limit held it back (the monthly
 * budget or the share per answer), instead of starting a run that could
 * only stop at once.
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
     * The limit that holds a long answer back, or null when it may start:
     * PayerEmpty for the monthly budget, CostLimit for the share per answer.
     *
     * @param list<array<string, mixed>> $messages the first call's messages
     */
    public function holdsBack(User $payer, ?Model $model, array $messages): ?ChatRunEnd
    {
        $allowance = $this->budget->allowance($payer);
        if (!$allowance->allowed) {
            return ChatRunEnd::PayerEmpty;
        }
        $limit = ChatRunCostGuard::room(0, $allowance);
        $rates = $this->rates($model);
        if (null === $limit || null === $rates) {
            return null;
        }
        $call = $rates->call(ChatRunCostEstimate::chars($messages));
        if ($call + (new ChatRunCostGuard($this->budget, $this->limits))->reserve($call, $limit) < $limit) {
            return null;
        }

        return ChatRunCostGuard::payerBinds(0, $allowance) ? ChatRunEnd::PayerEmpty : ChatRunEnd::CostLimit;
    }

    /** The model's price as the person is billed for it, or null without one. */
    public function rates(?Model $model): ?ChatRunRates
    {
        return ChatRunRates::of($model, $this->rates->getMarkupPercent());
    }
}
