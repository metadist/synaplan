<?php

declare(strict_types=1);

namespace App\Service\Iap;

/**
 * MOBILE-APP SEAM (Epic 5.4): a store-agnostic, server-verified snapshot of one
 * IAP subscription. Both the verify endpoint and the store-to-server
 * notifications (Apple ASSN V2 / Google RTDN) produce this same value object,
 * so {@see \App\Service\MobilePurchaseService} has a single code path.
 *
 * Never construct this from client-supplied data directly — it is only ever
 * returned by a verifier that has cryptographically checked the receipt
 * (Apple JWS chain) or queried the store API (Google).
 */
final readonly class IapEntitlement
{
    public function __construct(
        public IapPlatform $platform,
        public string $productId,
        /**
         * Stable per-subscription identity used for replay protection + matching
         * notifications back to the owning user: Apple `originalTransactionId`,
         * Google `purchaseToken`. One purchaseId belongs to exactly one user.
         */
        public string $purchaseId,
        public IapEntitlementState $state,
        /** Subscription expiry as a Unix timestamp (seconds), or null if unknown. */
        public ?int $expiresAt = null,
        public bool $autoRenew = false,
        /** 'sandbox' | 'production' — store test purchases must be distinguishable. */
        public string $environment = 'production',
        /**
         * Optional owner hint the client attached at purchase time (Apple
         * `appAccountToken`, Google `obfuscatedExternalAccountId`). Used as a
         * secondary anti-fraud check when present and parseable.
         */
        public ?string $accountBinding = null,
    ) {
    }

    public function grantsAccess(): bool
    {
        return $this->state->grantsAccess();
    }

    public function isSandbox(): bool
    {
        return 'sandbox' === $this->environment;
    }
}
