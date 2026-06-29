<?php

declare(strict_types=1);

namespace App\Service\Iap;

use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;

/**
 * MOBILE-APP SEAM (Epic 5.4): verifies Google Play subscriptions server-side.
 *
 * The real {@see GooglePlayVerifier} calls the Play Developer API
 * (`purchases.subscriptionsv2.get`) with a service account; the interface lets
 * {@see \App\Service\MobilePurchaseService} be tested with a fake instead.
 */
interface GooglePlayVerifierInterface
{
    /** True once a Google service account + package name are configured. */
    public function isConfigured(): bool;

    /**
     * Look up the current state of a purchase token via the Play Developer API
     * and return a normalized entitlement.
     *
     * @throws IapNotConfiguredException when Google credentials are absent
     * @throws IapVerificationException  when the token is invalid / rejected
     */
    public function verifyPurchaseToken(string $productId, string $purchaseToken): IapEntitlement;

    /**
     * Acknowledge a granted purchase within Google's 3-day window (else it is
     * auto-refunded). Idempotent and best-effort — already-acknowledged
     * purchases are a no-op.
     */
    public function acknowledge(string $productId, string $purchaseToken): void;

    /**
     * Decode a Real-time Developer Notification (Pub/Sub push body), query the
     * current purchase state, and return the entitlement — or null for messages
     * we don't act on (test pings, one-time products, voided non-subscriptions).
     *
     * @throws IapVerificationException when the envelope is malformed
     */
    public function decodeNotification(string $rawBody): ?IapEntitlement;
}
