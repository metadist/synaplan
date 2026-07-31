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
 * about net revenue; this service owns the product-id ↔ tier mapping and the
 * ASC/Play catalogue prices used as the in-app display fallback.
 *
 * Product IDs and store price points are injected from env (configured once the
 * store products exist, Epic 5.5). A tier without a product ID is simply not
 * purchasable via IAP, so open-source / web-only deployments keep working with
 * IAP disabled via {@see isConfigured()} without any extra configuration.
 *
 * The product IDs therefore default to EMPTY rather than to sample values. An
 * earlier revision shipped `com.synaplan.app.<tier>.monthly` as "placeholder"
 * defaults and treated exactly those strings as "not configured" — but those
 * are the real App Store Connect IDs of the German launch, so a fully
 * configured deployment reported `iapConfigured: false`. Never reintroduce a
 * sentinel that a real store product could legitimately use.
 */
final readonly class IapPricingService
{
    /** Default store commission passed on to app buyers (Apple/Google ≈30 %). */
    private const DEFAULT_STORE_MARKUP_PERCENT = 30.0;

    /**
     * Default App Store / Play EUR price points (German launch catalogue).
     * PRO is intentionally €24.99 (slightly under a pure 30 % pass-through).
     */
    private const DEFAULT_STORE_PRICE_PRO = 24.99;
    private const DEFAULT_STORE_PRICE_TEAM = 64.99;
    private const DEFAULT_STORE_PRICE_BUSINESS = 129.99;

    public function __construct(
        private string $iapProductPro = '',
        private string $iapProductTeam = '',
        private string $iapProductBusiness = '',
        private float $storeMarkupPercent = self::DEFAULT_STORE_MARKUP_PERCENT,
        private float $storePricePro = self::DEFAULT_STORE_PRICE_PRO,
        private float $storePriceTeam = self::DEFAULT_STORE_PRICE_TEAM,
        private float $storePriceBusiness = self::DEFAULT_STORE_PRICE_BUSINESS,
    ) {
    }

    /**
     * Markup-only price: web price + store-commission markup, snapped to the
     * nearest x.99 store price point. Used when no fixed store price is set for
     * a tier, and by unit tests of the snap math.
     */
    public function appPrice(float $webPrice): float
    {
        $markup = max(0.0, $this->storeMarkupPercent);
        $raw = $webPrice * (1 + $markup / 100);

        // Nearest x.99 price point; never undercut the operator's web price
        // (the app must not advertise below web — anti-steering).
        $pricePoint = round($raw + 0.01) - 0.01;
        if ($pricePoint < $webPrice) {
            $pricePoint += 1.0;
        }

        return round($pricePoint, 2);
    }

    /**
     * Price shown as the native-app fallback for a tier: the configured store
     * catalogue price (App Store Connect / Play) when set, otherwise the
     * markup snap of {@see appPrice()}. Never below the web price.
     *
     * The store's own localized price always wins in the purchase sheet once
     * the native catalogue is loaded.
     */
    public function appPriceForTier(string $tier, float $webPrice): float
    {
        $fixed = match ($tier) {
            'PRO' => $this->storePricePro,
            'TEAM' => $this->storePriceTeam,
            'BUSINESS' => $this->storePriceBusiness,
            default => 0.0,
        };

        if ($fixed > 0.0) {
            return round(max($fixed, $webPrice), 2);
        }

        return $this->appPrice($webPrice);
    }

    /** The configured store-commission markup in percent (never negative). */
    public function storeMarkupPercent(): float
    {
        return max(0.0, $this->storeMarkupPercent);
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
     * is not purchasable via IAP (e.g. NEW / ADMIN, or an unconfigured tier).
     */
    public function productIdForTier(string $tier): ?string
    {
        $productId = match ($tier) {
            'PRO' => $this->iapProductPro,
            'TEAM' => $this->iapProductTeam,
            'BUSINESS' => $this->iapProductBusiness,
            default => '',
        };

        return '' !== $productId ? $productId : null;
    }

    /**
     * Tier → store product ID for every tier that HAS a configured product, in
     * ascending order. Tiers without one are omitted: they cannot be bought via
     * IAP, and the app must not register an empty product with the store.
     *
     * @return array<string, string>
     */
    public function productCatalogue(): array
    {
        return array_filter([
            'PRO' => $this->iapProductPro,
            'TEAM' => $this->iapProductTeam,
            'BUSINESS' => $this->iapProductBusiness,
        ], static fn (string $productId): bool => '' !== $productId);
    }

    /**
     * True once at least one tier points at a store product — i.e. the
     * deployment has actually configured IAP. Mirrors
     * {@see BillingService::isEnabled()} for the Stripe side.
     */
    public function isConfigured(): bool
    {
        return [] !== $this->productCatalogue();
    }
}
