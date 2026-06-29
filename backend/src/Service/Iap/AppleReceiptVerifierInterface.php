<?php

declare(strict_types=1);

namespace App\Service\Iap;

use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;

/**
 * MOBILE-APP SEAM (Epic 5.4): verifies Apple StoreKit 2 data server-side.
 *
 * The interface exists so {@see \App\Service\MobilePurchaseService} can be unit
 * tested with a fake — the real {@see AppleStoreKitVerifier} performs the JWS
 * cert-chain verification (Apple App Store Server Library) and needs real
 * credentials, which are account-bound and unavailable in tests / CI.
 */
interface AppleReceiptVerifierInterface
{
    /** True once Apple credentials (root certs + bundle id) are configured. */
    public function isConfigured(): bool;

    /**
     * Verify a signed StoreKit 2 transaction JWS sent by the app and return a
     * normalized entitlement.
     *
     * @throws IapNotConfiguredException when Apple credentials are absent
     * @throws IapVerificationException  when the JWS is invalid / untrusted
     */
    public function verifySignedTransaction(string $signedTransaction): IapEntitlement;

    /**
     * Verify + decode an App Store Server Notification V2 `signedPayload` and
     * return the entitlement it describes (renew/expire/refund/grace/…).
     *
     * @throws IapNotConfiguredException when Apple credentials are absent
     * @throws IapVerificationException  when the payload is invalid / untrusted
     */
    public function verifyNotification(string $signedPayload): IapEntitlement;
}
