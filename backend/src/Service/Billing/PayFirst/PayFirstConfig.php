<?php

declare(strict_types=1);

namespace App\Service\Billing\PayFirst;

use App\Repository\ConfigRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Billing\BillingService;

/**
 * Pay first: a person without an account enters an email address, pays in
 * Stripe Checkout, and the account is created after the payment.
 *
 * Active only when all three hold: Stripe billing is configured, self-sign-up
 * is on, and the switch is on. The switch is the env `BILLING_PAY_FIRST` when
 * set, else BCONFIG `BILLING.PAY_FIRST`, default off — so open sign-up,
 * OIDC-only installs and installs without Stripe keep their own process.
 */
final readonly class PayFirstConfig
{
    public const CONFIG_GROUP = 'BILLING';
    public const KEY = 'PAY_FIRST';
    public const ENV_VAR = 'BILLING_PAY_FIRST';

    public function __construct(
        private ConfigRepository $configRepository,
        private BillingService $billing,
        private RegistrationConfig $registration,
    ) {
    }

    public function isActive(): bool
    {
        return $this->isSwitchedOn() && $this->billing->isEnabled() && $this->registration->isEnabled();
    }

    /**
     * Whether a paid pay-first session can still become an account. Only
     * billing matters: a payment taken before the switch went off keeps its
     * account.
     */
    public function canSettle(): bool
    {
        return $this->billing->isEnabled();
    }

    public function isSwitchedOn(): bool
    {
        $fromEnv = $this->envOverride();
        if (null !== $fromEnv) {
            return $fromEnv;
        }

        $stored = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY);

        return null !== $stored && (filter_var($stored, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false);
    }

    /** The explicit environment value, or null when unset or empty. */
    public function envOverride(): ?bool
    {
        $raw = trim((string) ($_ENV[self::ENV_VAR] ?? ''));
        if ('' === $raw) {
            return null;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }
}
