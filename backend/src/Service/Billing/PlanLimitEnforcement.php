<?php

declare(strict_types=1);

namespace App\Service\Billing;

use App\Repository\ConfigRepository;

/**
 * Whether the `RATELIMITS_{level}` plan limits apply on this installation.
 *
 * With billing (Stripe) set up they always apply. Without billing they apply
 * only when `SYSTEM_FLAGS.PLAN_LIMITS_WITHOUT_BILLING` is on (Admin →
 * Configuration). A new installation stores `true` when its first administrator is created
 * ({@see \App\Service\Setup\FreshInstallDefaults}); an installation that
 * existed before has no row and keeps unlimited usage without billing.
 */
final readonly class PlanLimitEnforcement
{
    public const CONFIG_GROUP = 'SYSTEM_FLAGS';
    public const KEY_WITHOUT_BILLING = 'PLAN_LIMITS_WITHOUT_BILLING';

    public function __construct(
        private BillingService $billingService,
        private ConfigRepository $configRepository,
    ) {
    }

    public function isEnforced(): bool
    {
        if ($this->billingService->isEnabled()) {
            return true;
        }

        $stored = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_WITHOUT_BILLING);

        return null !== $stored && filter_var($stored, \FILTER_VALIDATE_BOOL);
    }
}
