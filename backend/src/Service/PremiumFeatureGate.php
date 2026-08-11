<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Single decision point for "is this paid-tier feature available to the user?".
 *
 * Mirrors what {@see RateLimitService::checkLimit()} and
 * {@see StorageQuotaService::getStorageLimit()} already do: when no Stripe
 * configuration is present the install runs in open-source mode and every tier
 * gate opens. Without this, an install that never sells a subscription would
 * still block its own `NEW` users out of features, because the tier checks were
 * written for the hosted SaaS.
 *
 * That behaviour also has to hold for distribution channels: the AWS
 * Marketplace policies forbid a listed product from restricting functionality
 * by number of users, so the shipped default (no Stripe keys) must be
 * unrestricted.
 */
final readonly class PremiumFeatureGate
{
    /** Plan tiers that own the paid feature set once billing is configured. */
    public const PAID_LEVELS = ['PRO', 'TEAM', 'BUSINESS', 'ADMIN'];

    public function __construct(
        private BillingService $billingService,
        #[Autowire(env: 'default::bool:COST_BUDGET_GATE_ENABLED')]
        private bool $costBudgetGateEnabled = false,
    ) {
    }

    public function isUnlockedFor(User $user): bool
    {
        return $this->isUnlockedForLevel($user->getRateLimitLevel());
    }

    public function isUnlockedForLevel(string $level): bool
    {
        if (!$this->billingService->isEnabled()) {
            return true;
        }

        return $this->isPaidLevel($level);
    }

    public function isPaidLevel(string $level): bool
    {
        return \in_array(strtoupper($level), self::PAID_LEVELS, true);
    }

    /**
     * The cost budget is denominated in the plan a user pays for, so enforcing
     * it without billing would charge an install against a budget nobody sold.
     */
    public function isCostBudgetEnforced(): bool
    {
        return $this->costBudgetGateEnabled && $this->billingService->isEnabled();
    }
}
