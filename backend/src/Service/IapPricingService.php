<?php

declare(strict_types=1);

namespace App\Service;

/**
 * MOBILE-APP SEAM (Epic 5.5): channel-aware IAP pricing / product mapping.
 *
 * The web buys via Stripe (see {@see SubscriptionController}); the app buys via
 * native in-app purchase (Apple / Google). This service is the authoritative
 * map between a store product ID and a Synaplan tier — the IAP mirror of
 * `SubscriptionController::mapPriceIdToLevel()` for Stripe price IDs.
 *
 * The STORE price for each tier intentionally BAKES IN the store commission
 * (≈30 %, sometimes 15 % — first $1M/yr or Google subs in year 2+). That
 * assumption is documented in `docs/PAYMENTS_CHANNELS.md` so finance can reason
 * about net revenue; this service only owns the product-id ↔ tier mapping.
 *
 * Product IDs are injected from env (configured once the store products exist,
 * Epic 5.5). Defaults are the documented placeholder naming convention so that
 * open-source / web-only deployments keep working with IAP effectively disabled.
 */
final readonly class IapPricingService
{
    /** Placeholder product IDs shipped as defaults (no real store products yet). */
    private const PLACEHOLDER_PRO = 'com.synaplan.app.pro.monthly';
    private const PLACEHOLDER_TEAM = 'com.synaplan.app.team.monthly';
    private const PLACEHOLDER_BUSINESS = 'com.synaplan.app.business.monthly';

    public function __construct(
        private string $iapProductPro = self::PLACEHOLDER_PRO,
        private string $iapProductTeam = self::PLACEHOLDER_TEAM,
        private string $iapProductBusiness = self::PLACEHOLDER_BUSINESS,
    ) {
    }

    /**
     * Map a store product ID to the Synaplan tier it grants.
     *
     * Returns 'NEW' (no entitlement) for an unknown / empty product so an
     * unexpected receipt can never silently grant a paid tier.
     *
     * @return 'NEW'|'PRO'|'TEAM'|'BUSINESS'
     */
    public function mapProductIdToLevel(?string $productId): string
    {
        if (null === $productId || '' === $productId) {
            return 'NEW';
        }

        return match ($productId) {
            $this->iapProductPro => 'PRO',
            $this->iapProductTeam => 'TEAM',
            $this->iapProductBusiness => 'BUSINESS',
            default => 'NEW',
        };
    }

    /**
     * The store product ID configured for a given tier, or null for a tier that
     * is not purchasable via IAP (e.g. NEW / ADMIN).
     */
    public function productIdForTier(string $tier): ?string
    {
        return match ($tier) {
            'PRO' => $this->iapProductPro,
            'TEAM' => $this->iapProductTeam,
            'BUSINESS' => $this->iapProductBusiness,
            default => null,
        };
    }

    /**
     * Tier → store product ID for every IAP-purchasable tier, in ascending order.
     *
     * @return array<string, string>
     */
    public function productCatalogue(): array
    {
        return [
            'PRO' => $this->iapProductPro,
            'TEAM' => $this->iapProductTeam,
            'BUSINESS' => $this->iapProductBusiness,
        ];
    }

    /**
     * True once at least one tier points at a real (non-placeholder) store
     * product — i.e. the deployment has actually configured IAP. Mirrors
     * {@see BillingService::isEnabled()} for the Stripe side.
     */
    public function isConfigured(): bool
    {
        $placeholders = [self::PLACEHOLDER_PRO, self::PLACEHOLDER_TEAM, self::PLACEHOLDER_BUSINESS, ''];

        foreach ($this->productCatalogue() as $productId) {
            if (!in_array($productId, $placeholders, true)) {
                return true;
            }
        }

        return false;
    }
}
