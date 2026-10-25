<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Entity\Subscription;
use App\Repository\SubscriptionRepository;

/**
 * Whether a paid tier is for sale right now: checkout must be configured, and
 * an operator may have deactivated the tier in BSUBSCRIPTIONS. A tier without
 * a catalogue row is for sale (its Stripe price decides). One rule for every
 * way in — the register page, pay-first and the plan list.
 */
final readonly class PlanAvailability
{
    public function __construct(
        private BillingService $billing,
        private SubscriptionRepository $subscriptions,
    ) {
    }

    public function isPurchasable(string $plan): bool
    {
        if (!$this->billing->isEnabled()) {
            return false;
        }

        $row = $this->subscriptions->findByLevelAnyState($plan);

        return !$row instanceof Subscription || $row->isActive();
    }
}
