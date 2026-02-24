<?php

namespace App\Service;

/**
 * Centralized service to check if billing/Stripe is properly configured.
 * Used to toggle between "SaaS Mode" (limits, upgrades) and "Open Source Mode" (unlimited).
 */
final readonly class BillingService
{
    public function __construct(
        private string $stripeSecretKey,
        private string $stripePricePro,
    ) {
    }

    /**
     * Check if billing is enabled (valid Stripe config present).
     */
    public function isEnabled(): bool
    {
        // Must have a real Stripe secret key (not placeholder)
        if (empty($this->stripeSecretKey) || str_contains($this->stripeSecretKey, 'your_')) {
            return false;
        }

        // Must have real price IDs (not placeholders)
        if (empty($this->stripePricePro)
            || in_array($this->stripePricePro, ['price_xxx', 'price_pro', 'price_pro_monthly'], true)) {
            return false;
        }

        return true;
    }
}
