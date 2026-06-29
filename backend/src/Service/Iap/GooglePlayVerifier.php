<?php

declare(strict_types=1);

namespace App\Service\Iap;

use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;
use Google\Client as GoogleClient;
use Google\Service\AndroidPublisher;
use Google\Service\AndroidPublisher\AutoRenewingPlan;
use Google\Service\AndroidPublisher\ExternalAccountIdentifiers;
use Google\Service\AndroidPublisher\SubscriptionPurchaseLineItem;
use Google\Service\AndroidPublisher\SubscriptionPurchasesAcknowledgeRequest;
use Google\Service\AndroidPublisher\SubscriptionPurchaseV2;
use Google\Service\AndroidPublisher\TestPurchase;
use Google\Service\Exception as GoogleServiceException;
use Psr\Log\LoggerInterface;

/**
 * MOBILE-APP SEAM (Epic 5.4): real Google Play verifier.
 *
 * Thin adapter over the Play Developer API (`purchases.subscriptionsv2.get`)
 * via a service account. The server always re-queries Google for the actual
 * purchase state (truth from Google, never the client or the RTDN body), so a
 * forged request can never grant a tier.
 *
 * NOTE: intentionally NOT unit tested — it needs a real service account +
 * package name and live API responses. The consuming business logic is tested
 * against {@see GooglePlayVerifierInterface} fakes instead.
 */
final class GooglePlayVerifier implements GooglePlayVerifierInterface
{
    private ?AndroidPublisher $service = null;

    public function __construct(
        private readonly string $packageName,
        private readonly string $serviceAccountJsonPath,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isConfigured(): bool
    {
        return '' !== $this->packageName
            && '' !== $this->serviceAccountJsonPath
            && is_file($this->serviceAccountJsonPath);
    }

    public function verifyPurchaseToken(string $productId, string $purchaseToken): IapEntitlement
    {
        $service = $this->service();

        try {
            $purchase = $service->purchases_subscriptionsv2->get($this->packageName, $purchaseToken);
        } catch (GoogleServiceException $e) {
            throw new IapVerificationException('Google purchase verification failed: '.$e->getMessage(), 0, $e);
        }

        return $this->toEntitlement($productId, $purchaseToken, $purchase);
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        if ('' === $productId) {
            return;
        }

        $service = $this->service();

        try {
            $service->purchases_subscriptions->acknowledge(
                $this->packageName,
                $productId,
                $purchaseToken,
                new SubscriptionPurchasesAcknowledgeRequest(),
            );
        } catch (GoogleServiceException $e) {
            // Already-acknowledged purchases return an error we can safely
            // ignore; log everything else but never fail the grant on ack.
            $this->logger->info('Google acknowledge skipped/failed (non-fatal)', [
                'product' => $productId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function decodeNotification(string $rawBody): ?IapEntitlement
    {
        $envelope = json_decode($rawBody, true);
        if (!is_array($envelope)) {
            throw new IapVerificationException('Google RTDN body is not valid JSON.');
        }

        // Pub/Sub push: { "message": { "data": "<base64>" }, "subscription": "..." }.
        $encoded = $envelope['message']['data'] ?? null;
        if (!is_string($encoded) || '' === $encoded) {
            throw new IapVerificationException('Google RTDN envelope has no message data.');
        }

        $decoded = base64_decode($encoded, true);
        if (false === $decoded) {
            throw new IapVerificationException('Google RTDN message data is not valid base64.');
        }

        $payload = json_decode($decoded, true);
        if (!is_array($payload)) {
            throw new IapVerificationException('Google RTDN payload is not valid JSON.');
        }

        // We only act on subscription notifications; test pings and one-time /
        // voided-purchase messages are acknowledged without action.
        $sub = $payload['subscriptionNotification'] ?? null;
        if (!is_array($sub)) {
            return null;
        }

        $purchaseToken = (string) ($sub['purchaseToken'] ?? '');
        $subscriptionId = (string) ($sub['subscriptionId'] ?? '');
        if ('' === $purchaseToken) {
            return null;
        }

        // Re-query the real state from Google — never trust the notification body.
        return $this->verifyPurchaseToken($subscriptionId, $purchaseToken);
    }

    private function toEntitlement(string $fallbackProductId, string $purchaseToken, SubscriptionPurchaseV2 $purchase): IapEntitlement
    {
        // The Google client models declare optimistic non-null phpdoc types, but
        // fields Google omits are null at runtime — annotate accordingly so the
        // null checks below are honest (and not flagged as "always true").
        $lineItem = $this->firstLineItem($purchase);

        $productId = $fallbackProductId;
        $expiresAt = null;
        $autoRenew = false;
        if (null !== $lineItem) {
            /** @var string|null $pid */
            $pid = $lineItem->getProductId();
            if (null !== $pid && '' !== $pid) {
                $productId = $pid;
            }

            /** @var string|null $expiryRaw */
            $expiryRaw = $lineItem->getExpiryTime();
            if (null !== $expiryRaw && '' !== $expiryRaw) {
                $expiresAt = strtotime($expiryRaw) ?: null;
            }

            /** @var AutoRenewingPlan|null $plan */
            $plan = $lineItem->getAutoRenewingPlan();
            $autoRenew = null !== $plan && true === $plan->getAutoRenewEnabled();
        }

        /** @var TestPurchase|null $testPurchase */
        $testPurchase = $purchase->getTestPurchase();

        $binding = null;
        /** @var ExternalAccountIdentifiers|null $identifiers */
        $identifiers = $purchase->getExternalAccountIdentifiers();
        if (null !== $identifiers) {
            /** @var string|null $obfuscated */
            $obfuscated = $identifiers->getObfuscatedExternalAccountId();
            $binding = $obfuscated;
        }

        /** @var string|null $state */
        $state = $purchase->getSubscriptionState();

        return new IapEntitlement(
            platform: IapPlatform::GOOGLE,
            productId: $productId,
            purchaseId: $purchaseToken,
            state: $this->mapState($state ?? ''),
            expiresAt: $expiresAt,
            autoRenew: $autoRenew,
            environment: null !== $testPurchase ? 'sandbox' : 'production',
            accountBinding: $binding,
        );
    }

    private function firstLineItem(SubscriptionPurchaseV2 $purchase): ?SubscriptionPurchaseLineItem
    {
        /** @var SubscriptionPurchaseLineItem[]|null $items */
        $items = $purchase->getLineItems();
        if (empty($items)) {
            return null;
        }

        return $items[array_key_first($items)];
    }

    private function mapState(string $state): IapEntitlementState
    {
        return match ($state) {
            SubscriptionPurchaseV2::SUBSCRIPTION_STATE_SUBSCRIPTION_STATE_ACTIVE,
            // Canceled but still inside the paid period — access until expiry,
            // just without auto-renew.
            SubscriptionPurchaseV2::SUBSCRIPTION_STATE_SUBSCRIPTION_STATE_CANCELED => IapEntitlementState::ACTIVE,
            SubscriptionPurchaseV2::SUBSCRIPTION_STATE_SUBSCRIPTION_STATE_IN_GRACE_PERIOD => IapEntitlementState::GRACE_PERIOD,
            SubscriptionPurchaseV2::SUBSCRIPTION_STATE_SUBSCRIPTION_STATE_ON_HOLD,
            SubscriptionPurchaseV2::SUBSCRIPTION_STATE_SUBSCRIPTION_STATE_PAUSED => IapEntitlementState::ON_HOLD,
            SubscriptionPurchaseV2::SUBSCRIPTION_STATE_SUBSCRIPTION_STATE_PENDING => IapEntitlementState::PENDING,
            default => IapEntitlementState::EXPIRED,
        };
    }

    private function service(): AndroidPublisher
    {
        if (!$this->isConfigured()) {
            throw new IapNotConfiguredException('Google IAP is not configured on this server.');
        }

        if (null === $this->service) {
            try {
                $client = new GoogleClient();
                $client->setAuthConfig($this->serviceAccountJsonPath);
                $client->addScope(AndroidPublisher::ANDROIDPUBLISHER);
                $this->service = new AndroidPublisher($client);
            } catch (\Throwable $e) {
                throw new IapNotConfiguredException('Google verifier could not be initialized: '.$e->getMessage(), 0, $e);
            }
        }

        return $this->service;
    }
}
