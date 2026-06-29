<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Iap\AppleReceiptVerifierInterface;
use App\Service\Iap\Exception\IapConflictException;
use App\Service\Iap\Exception\IapNotConfiguredException;
use App\Service\Iap\Exception\IapVerificationException;
use App\Service\Iap\GooglePlayVerifierInterface;
use App\Service\Iap\IapEntitlement;
use App\Service\Iap\IapEntitlementState;
use App\Service\Iap\IapPlatform;
use App\Service\IapPricingService;
use App\Service\MobilePurchaseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for self-hosted IAP validation (Epic 5.4).
 *
 * The store verifiers are faked, so the business logic — product→tier mapping,
 * block-cross, replay protection, PENDING handling, grant/revoke, and
 * notification application — is covered without any real store call or account.
 */
final class MobilePurchaseServiceTest extends TestCase
{
    private const APPLE_TX = 'orig-tx-123';
    private const GOOGLE_TOKEN = 'purchase-token-abc';

    public function testRedeemAppleGrantsTierAndStampsSource(): void
    {
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $service = $this->service(apple: $apple);
        $user = $this->makeUser(1);

        $result = $service->redeem($user, IapPlatform::APPLE, 'signed-jws');

        $this->assertTrue($result->granted);
        $this->assertFalse($result->pending);
        $this->assertSame('PRO', $result->tier);
        $this->assertSame('apple', $result->source);
        $this->assertSame('PRO', $user->getUserLevel());

        $sub = $user->getPaymentDetails()['subscription'];
        $this->assertSame('apple', $sub['source']);
        $this->assertSame('PRO', $sub['plan']);
        $this->assertSame(self::APPLE_TX, $sub['original_transaction_id']);
    }

    public function testRedeemGoogleGrantsAndAcknowledges(): void
    {
        $google = new FakeGoogleVerifier($this->entitlement(IapPlatform::GOOGLE, 'app.team', self::GOOGLE_TOKEN));
        $service = $this->service(google: $google);
        $user = $this->makeUser(2);

        $result = $service->redeem($user, IapPlatform::GOOGLE, self::GOOGLE_TOKEN, 'app.team');

        $this->assertTrue($result->granted);
        $this->assertSame('TEAM', $result->tier);
        $this->assertSame('TEAM', $user->getUserLevel());
        $this->assertSame(self::GOOGLE_TOKEN, $user->getPaymentDetails()['subscription']['purchase_token']);
        $this->assertTrue($google->acknowledged, 'granted Google purchases must be acknowledged');
    }

    public function testUnknownProductIsRejected(): void
    {
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.unknown', self::APPLE_TX));
        $service = $this->service(apple: $apple);

        $this->expectException(IapVerificationException::class);
        $service->redeem($this->makeUser(1), IapPlatform::APPLE, 'signed-jws');
    }

    public function testPendingPurchaseIsNotUnlocked(): void
    {
        $google = new FakeGoogleVerifier(
            $this->entitlement(IapPlatform::GOOGLE, 'app.pro', self::GOOGLE_TOKEN, IapEntitlementState::PENDING)
        );
        $service = $this->service(google: $google);
        $user = $this->makeUser(3);

        $result = $service->redeem($user, IapPlatform::GOOGLE, self::GOOGLE_TOKEN, 'app.pro');

        $this->assertTrue($result->pending);
        $this->assertFalse($result->granted);
        $this->assertSame('NEW', $user->getUserLevel(), 'pending purchase must not change the level');
        $this->assertFalse($google->acknowledged, 'pending purchase must not be acknowledged');
    }

    public function testBlockCrossWhenStripeSubscriptionActive(): void
    {
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $service = $this->service(apple: $apple);
        $user = $this->makeUser(4, 'PRO', [
            'subscription' => [
                'source' => 'stripe',
                'status' => 'active',
                'subscription_end' => time() + 3600,
                'stripe_subscription_id' => 'sub_live',
            ],
        ]);

        $this->expectException(IapConflictException::class);
        $service->redeem($user, IapPlatform::APPLE, 'signed-jws');
    }

    public function testReplayBlockedWhenReceiptOwnedByAnotherUser(): void
    {
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $otherOwner = $this->makeUser(999);
        $service = $this->service(apple: $apple, ownerByPurchaseId: $otherOwner);

        $this->expectException(IapConflictException::class);
        $service->redeem($this->makeUser(5), IapPlatform::APPLE, 'signed-jws');
    }

    public function testReplayAllowedForSameUser(): void
    {
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $user = $this->makeUser(6);
        $service = $this->service(apple: $apple, ownerByPurchaseId: $user);

        $result = $service->redeem($user, IapPlatform::APPLE, 'signed-jws');

        $this->assertTrue($result->granted);
        $this->assertSame('PRO', $user->getUserLevel());
    }

    public function testNotConfiguredPropagates(): void
    {
        $apple = new FakeAppleVerifier(null);
        $apple->configured = false;
        $service = $this->service(apple: $apple);

        $this->expectException(IapNotConfiguredException::class);
        $service->redeem($this->makeUser(7), IapPlatform::APPLE, 'signed-jws');
    }

    public function testNotificationRenewSetsTier(): void
    {
        $user = $this->makeUser(8, 'NEW', [
            'subscription' => ['source' => 'apple', 'status' => 'expired', 'original_transaction_id' => self::APPLE_TX],
        ]);
        $service = $this->service(ownerByPurchaseId: $user);

        $entitlement = $this->entitlement(IapPlatform::APPLE, 'app.business', self::APPLE_TX, IapEntitlementState::ACTIVE);
        $handled = $service->applyNotification($entitlement);

        $this->assertTrue($handled);
        $this->assertSame('BUSINESS', $user->getUserLevel());
        $this->assertSame('active', $user->getPaymentDetails()['subscription']['status']);
    }

    public function testNotificationRefundRevokesAccess(): void
    {
        $user = $this->makeUser(9, 'PRO', [
            'subscription' => ['source' => 'apple', 'status' => 'active', 'original_transaction_id' => self::APPLE_TX],
        ]);
        $service = $this->service(ownerByPurchaseId: $user);

        $entitlement = $this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, IapEntitlementState::REVOKED);
        $service->applyNotification($entitlement);

        $this->assertSame('NEW', $user->getUserLevel());
        $this->assertSame('refunded', $user->getPaymentDetails()['subscription']['status']);
    }

    public function testNotificationGracePeriodKeepsAccess(): void
    {
        $user = $this->makeUser(10, 'PRO', [
            'subscription' => ['source' => 'apple', 'status' => 'active', 'original_transaction_id' => self::APPLE_TX],
        ]);
        $service = $this->service(ownerByPurchaseId: $user);

        $entitlement = $this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, IapEntitlementState::GRACE_PERIOD);
        $service->applyNotification($entitlement);

        $this->assertSame('PRO', $user->getUserLevel(), 'grace period keeps access');
        $this->assertSame('grace_period', $user->getPaymentDetails()['subscription']['status']);
    }

    public function testNotificationForUnknownPurchaseIsIgnored(): void
    {
        $service = $this->service(ownerByPurchaseId: null);

        $entitlement = $this->entitlement(IapPlatform::GOOGLE, 'app.pro', 'unknown-token', IapEntitlementState::ACTIVE);

        $this->assertFalse($service->applyNotification($entitlement));
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function service(
        ?FakeAppleVerifier $apple = null,
        ?FakeGoogleVerifier $google = null,
        ?User $ownerByPurchaseId = null,
    ): MobilePurchaseService {
        $em = $this->createStub(EntityManagerInterface::class);

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findByIapPurchaseId')->willReturn($ownerByPurchaseId);

        return new MobilePurchaseService(
            $em,
            $repo,
            new IapPricingService('app.pro', 'app.team', 'app.business'),
            $apple ?? new FakeAppleVerifier(null),
            $google ?? new FakeGoogleVerifier(null),
            new NullLogger(),
        );
    }

    private function entitlement(
        IapPlatform $platform,
        string $productId,
        string $purchaseId,
        IapEntitlementState $state = IapEntitlementState::ACTIVE,
    ): IapEntitlement {
        return new IapEntitlement(
            platform: $platform,
            productId: $productId,
            purchaseId: $purchaseId,
            state: $state,
            expiresAt: time() + 86400,
            autoRenew: true,
            environment: 'sandbox',
        );
    }

    /**
     * @param array<string, mixed> $paymentDetails
     */
    private function makeUser(int $id, string $level = 'NEW', array $paymentDetails = []): User
    {
        $user = new User();
        $user->setUserLevel($level);
        if ([] !== $paymentDetails) {
            $user->setPaymentDetails($paymentDetails);
        }

        $ref = new \ReflectionProperty(User::class, 'id');
        $ref->setValue($user, $id);

        return $user;
    }
}

/**
 * Configurable fake Apple verifier — returns a canned entitlement.
 */
final class FakeAppleVerifier implements AppleReceiptVerifierInterface
{
    public bool $configured = true;

    public function __construct(private readonly ?IapEntitlement $entitlement)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function verifySignedTransaction(string $signedTransaction): IapEntitlement
    {
        if (!$this->configured) {
            throw new IapNotConfiguredException('not configured');
        }

        return $this->entitlement ?? throw new IapVerificationException('no entitlement');
    }

    public function verifyNotification(string $signedPayload): IapEntitlement
    {
        return $this->entitlement ?? throw new IapVerificationException('no entitlement');
    }
}

/**
 * Configurable fake Google verifier — records acknowledge() calls.
 */
final class FakeGoogleVerifier implements GooglePlayVerifierInterface
{
    public bool $configured = true;
    public bool $acknowledged = false;

    public function __construct(private readonly ?IapEntitlement $entitlement)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function verifyPurchaseToken(string $productId, string $purchaseToken): IapEntitlement
    {
        if (!$this->configured) {
            throw new IapNotConfiguredException('not configured');
        }

        return $this->entitlement ?? throw new IapVerificationException('no entitlement');
    }

    public function acknowledge(string $productId, string $purchaseToken): void
    {
        $this->acknowledged = true;
    }

    public function decodeNotification(string $rawBody): ?IapEntitlement
    {
        return $this->entitlement;
    }
}
