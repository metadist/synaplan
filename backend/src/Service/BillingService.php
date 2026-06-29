<?php

namespace App\Service;

/**
 * Centralized service to check if billing/Stripe is properly configured.
 * Used to toggle between "SaaS Mode" (limits, upgrades) and "Open Source Mode" (unlimited).
 */
final readonly class BillingService
{
    /**
     * MOBILE-APP SEAM (Epic 5.1): the channels that can own a subscription.
     * Web buys via Stripe, the app buys via native IAP (Apple / Google).
     */
    public const SOURCE_STRIPE = 'stripe';
    public const SOURCE_APPLE = 'apple';
    public const SOURCE_GOOGLE = 'google';

    /** System subscription-management deep links for the IAP channels. */
    private const APPLE_MANAGE_URL = 'https://apps.apple.com/account/subscriptions';
    private const GOOGLE_MANAGE_URL = 'https://play.google.com/store/account/subscriptions';

    public function __construct(
        private string $stripeSecretKey,
        private string $stripePricePro,
    ) {
    }

    /**
     * Where the user manages an existing subscription, per owning channel.
     *
     * For Apple / Google this is the store's system subscription settings (a
     * stable, public deep link). For Stripe the manage flow is the on-demand
     * billing portal created by `SubscriptionController::createPortalSession()`,
     * so there is no static URL — callers should hit `POST /subscription/portal`.
     * Returns null for the Stripe / unknown / no-subscription case.
     */
    public function getManageUrl(?string $source): ?string
    {
        return match ($source) {
            self::SOURCE_APPLE => self::APPLE_MANAGE_URL,
            self::SOURCE_GOOGLE => self::GOOGLE_MANAGE_URL,
            default => null,
        };
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
