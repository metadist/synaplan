<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Iap\AppleReceiptVerifierInterface;
use App\Service\Iap\Exception\IapConflictException;
use App\Service\Iap\Exception\IapVerificationException;
use App\Service\Iap\GooglePlayVerifierInterface;
use App\Service\Iap\IapEntitlement;
use App\Service\Iap\IapEntitlementState;
use App\Service\Iap\IapPlatform;
use App\Service\Iap\RedeemResult;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * MOBILE-APP SEAM (Epic 5.4): self-hosted IAP validation — the single source of
 * truth for native subscription entitlement.
 *
 * The app sends a receipt; this service verifies it **server-side** (never
 * trusting a client success callback), maps the store product to a Synaplan
 * tier, enforces that a subscription is owned by exactly one channel
 * (block-cross with Stripe) and by exactly one user (replay protection), then
 * writes the entitlement into `BUSERLEVEL` + `BPAYMENTDETAILS` exactly like the
 * Stripe webhook does. Store-to-server notifications (Apple ASSN V2 / Google
 * RTDN) feed the same {@see applyEntitlement()} core so renew/cancel/refund are
 * handled without the app open.
 *
 * Verification is delegated to the {@see AppleReceiptVerifierInterface} /
 * {@see GooglePlayVerifierInterface} seams so the business logic here is fully
 * unit-testable with fakes — no real store calls, keys, or accounts needed.
 */
final readonly class MobilePurchaseService
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserRepository $userRepository,
        private IapPricingService $iapPricingService,
        private AppleReceiptVerifierInterface $appleVerifier,
        private GooglePlayVerifierInterface $googleVerifier,
        private LoggerInterface $logger,
    ) {
    }

    /** Whether at least one IAP channel is configured for this deployment. */
    public function isConfigured(): bool
    {
        return $this->appleVerifier->isConfigured() || $this->googleVerifier->isConfigured();
    }

    /**
     * Verify a fresh purchase and grant entitlement to the signed-in user.
     *
     * @param string $receipt   Apple: the signed StoreKit 2 transaction JWS.
     *                          Google: the purchase token.
     * @param string $productId store product id (Apple reads it from the JWS,
     *                          so it may be empty there; Google requires it)
     *
     * @throws IapVerificationException unknown/empty product, or invalid receipt
     * @throws IapConflictException     block-cross or replay (receipt owned by another user)
     */
    public function redeem(User $user, IapPlatform $platform, string $receipt, string $productId = ''): RedeemResult
    {
        $entitlement = match ($platform) {
            IapPlatform::APPLE => $this->appleVerifier->verifySignedTransaction($receipt),
            IapPlatform::GOOGLE => $this->googleVerifier->verifyPurchaseToken($productId, $receipt),
        };

        $tier = $this->iapPricingService->mapProductIdToLevel($entitlement->productId);
        if ('NEW' === $tier) {
            throw new IapVerificationException(sprintf('Unknown IAP product "%s" — refusing to grant a tier.', $entitlement->productId));
        }

        // Never unlock a PENDING purchase (Google deferred/SCA). Wait for the
        // RTDN that confirms it; do not touch the user's current level.
        if (IapEntitlementState::PENDING === $entitlement->state) {
            $this->logger->info('IAP purchase is pending, not unlocking', [
                'user_id' => $user->getId(),
                'platform' => $platform->value,
                'product' => $entitlement->productId,
            ]);

            return RedeemResult::pending($platform->source());
        }

        $this->assertNoCrossChannelConflict($user, $platform);
        $this->assertReceiptNotOwnedByAnotherUser($user, $entitlement);

        // Acknowledge Google purchases within the 3-day window once we've
        // decided to grant (Apple needs no acknowledgement).
        if (IapPlatform::GOOGLE === $platform && $entitlement->grantsAccess()) {
            $this->googleVerifier->acknowledge($entitlement->productId, $entitlement->purchaseId);
        }

        $this->applyEntitlement($user, $entitlement, $tier);

        $status = $this->statusFor($entitlement->state);
        $this->logger->info('IAP purchase redeemed', [
            'user_id' => $user->getId(),
            'platform' => $platform->value,
            'tier' => $tier,
            'status' => $status,
            'sandbox' => $entitlement->isSandbox(),
        ]);

        return $entitlement->grantsAccess()
            ? RedeemResult::granted($tier, $platform->source(), $status)
            : RedeemResult::notGranted('NEW', $platform->source(), $status);
    }

    /**
     * Verify + decode an Apple App Store Server Notification V2 signedPayload.
     *
     * @throws Iap\Exception\IapNotConfiguredException
     * @throws IapVerificationException
     */
    public function verifyAppleNotification(string $signedPayload): IapEntitlement
    {
        return $this->appleVerifier->verifyNotification($signedPayload);
    }

    /**
     * Decode a Google RTDN push body into an entitlement (re-querying the Play
     * API for the actual state). Returns null for messages we don't act on.
     *
     * @throws IapVerificationException
     */
    public function decodeGoogleNotification(string $rawBody): ?IapEntitlement
    {
        return $this->googleVerifier->decodeNotification($rawBody);
    }

    /**
     * Apply a store-to-server notification (Apple ASSN V2 / Google RTDN) to the
     * owning user. Finds the user by the receipt's purchaseId; no Bearer auth
     * and no block-cross/replay checks (the owner is already established).
     *
     * @return bool true when a user was found and updated; false when the
     *              notification referenced an unknown purchase (safe to ACK)
     */
    public function applyNotification(IapEntitlement $entitlement): bool
    {
        $user = $this->userRepository->findByIapPurchaseId($entitlement->purchaseId);
        if (!$user instanceof User) {
            $this->logger->info('IAP notification for unknown purchase, acknowledging', [
                'platform' => $entitlement->platform->value,
                'product' => $entitlement->productId,
            ]);

            return false;
        }

        // A notification should never resurrect a subscription the user no
        // longer owns (e.g. they switched channels). Only act while this
        // platform still owns the record.
        $ownerSource = $user->getSubscriptionSource();
        if (null !== $ownerSource && $ownerSource !== $entitlement->platform->source()) {
            $this->logger->info('IAP notification ignored: subscription owned by another channel', [
                'user_id' => $user->getId(),
                'owner' => $ownerSource,
                'notification_source' => $entitlement->platform->source(),
            ]);

            return false;
        }

        $tier = $this->iapPricingService->mapProductIdToLevel($entitlement->productId);
        if ('NEW' === $tier) {
            return false;
        }

        $this->applyEntitlement($user, $entitlement, $tier);

        $this->logger->info('IAP notification applied', [
            'user_id' => $user->getId(),
            'platform' => $entitlement->platform->value,
            'state' => $entitlement->state->value,
        ]);

        return true;
    }

    /**
     * Persist the entitlement onto the user: set the level when access is
     * granted (active/grace), downgrade to NEW otherwise, and stamp the
     * channel-aware `subscription` record. Mirrors the Stripe webhook's shape.
     */
    private function applyEntitlement(User $user, IapEntitlement $entitlement, string $tier): void
    {
        $grants = $entitlement->grantsAccess();
        $user->setUserLevel($grants ? $tier : 'NEW');

        $paymentDetails = $user->getPaymentDetails();
        $idKey = IapPlatform::APPLE === $entitlement->platform
            ? 'original_transaction_id'
            : 'purchase_token';

        $paymentDetails['subscription'] = [
            'source' => $entitlement->platform->source(),
            'status' => $this->statusFor($entitlement->state),
            'plan' => $grants ? $tier : 'NEW',
            'product_id' => $entitlement->productId,
            'subscription_end' => $entitlement->expiresAt,
            'cancel_at_period_end' => !$entitlement->autoRenew,
            'environment' => $entitlement->environment,
            $idKey => $entitlement->purchaseId,
        ];

        $user->setPaymentDetails($paymentDetails);
        $this->em->flush();
    }

    /**
     * Block-cross: a subscription may be owned by exactly one channel. If the
     * user already has an ACTIVE subscription from a *different* source, refuse
     * the IAP purchase (the Stripe checkout endpoint enforces the mirror rule).
     */
    private function assertNoCrossChannelConflict(User $user, IapPlatform $platform): void
    {
        if (!$user->hasActiveSubscription()) {
            return;
        }

        $existing = $user->getSubscriptionSource();
        if (null !== $existing && $existing !== $platform->source()) {
            throw new IapConflictException(sprintf('Active subscription already owned by "%s"; manage it there.', $existing));
        }
    }

    /**
     * Replay protection: a receipt grants entitlement to exactly one user. If
     * the purchaseId is already attached to a *different* account, reject it
     * (shared/leaked receipt). Re-redeeming on the same account is fine.
     */
    private function assertReceiptNotOwnedByAnotherUser(User $user, IapEntitlement $entitlement): void
    {
        $owner = $this->userRepository->findByIapPurchaseId($entitlement->purchaseId);
        if ($owner instanceof User && $owner->getId() !== $user->getId()) {
            $this->logger->warning('IAP receipt replay blocked: already owned by another user', [
                'requesting_user' => $user->getId(),
                'owning_user' => $owner->getId(),
                'platform' => $entitlement->platform->value,
            ]);

            throw new IapConflictException('This purchase is already linked to another account.');
        }
    }

    private function statusFor(IapEntitlementState $state): string
    {
        return match ($state) {
            IapEntitlementState::ACTIVE => 'active',
            IapEntitlementState::GRACE_PERIOD => 'grace_period',
            IapEntitlementState::ON_HOLD => 'on_hold',
            IapEntitlementState::EXPIRED => 'expired',
            IapEntitlementState::REVOKED => 'refunded',
            IapEntitlementState::PENDING => 'pending',
        };
    }
}
