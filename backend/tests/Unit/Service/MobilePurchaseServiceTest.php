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
use App\Service\Iap\IapPricingService;
use App\Service\Iap\IapPurchaseHistory;
use App\Service\Iap\MobilePurchaseService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Unit tests for self-hosted IAP validation (Epic 5.4).
 *
 * The store verifiers are faked, so the business logic — product→tier mapping,
 * block-cross, replay protection, refund replay, PENDING handling,
 * grant/revoke, and notification application — is covered without any real
 * store call or account.
 */
final class MobilePurchaseServiceTest extends TestCase
{
    use \App\Tests\Support\MemoryLevelGrants;
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

    public function testSameChannelParallelSubscriptionIsRejected(): void
    {
        // The account already has an ACTIVE Apple subscription under a
        // DIFFERENT originalTransactionId (e.g. bought by another Apple ID
        // signed in on the device). Applying the new receipt would silently
        // overwrite the record while the old subscription keeps billing.
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $service = $this->service(apple: $apple);
        $user = $this->makeUser(11, 'BUSINESS', [
            'subscription' => [
                'source' => 'apple',
                'status' => 'active',
                'subscription_end' => time() + 3600,
                'original_transaction_id' => 'other-apple-tx',
            ],
        ]);

        try {
            $service->redeem($user, IapPlatform::APPLE, 'signed-jws');
            $this->fail('Expected IapConflictException');
        } catch (IapConflictException) {
            $this->assertSame('BUSINESS', $user->getUserLevel(), 'existing entitlement must stay untouched');
            $this->assertSame(
                'other-apple-tx',
                $user->getPaymentDetails()['subscription']['original_transaction_id'],
                'existing subscription record must not be overwritten'
            );
        }
    }

    public function testSameChannelSamePurchaseIdIsAllowed(): void
    {
        // Renewal / re-redeem / Apple plan change: the originalTransactionId
        // stays stable, so the same store subscription may update itself.
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.team', self::APPLE_TX));
        $user = $this->makeUser(12, 'PRO', [
            'subscription' => [
                'source' => 'apple',
                'status' => 'active',
                'subscription_end' => time() + 3600,
                'original_transaction_id' => self::APPLE_TX,
            ],
        ]);
        $service = $this->service(apple: $apple, ownerByPurchaseId: $user);

        $result = $service->redeem($user, IapPlatform::APPLE, 'signed-jws');

        $this->assertTrue($result->granted);
        $this->assertSame('TEAM', $user->getUserLevel());
    }

    public function testSameChannelExpiredSubscriptionAllowsNewPurchase(): void
    {
        // A lapsed subscription is no conflict — re-subscribing with a fresh
        // store subscription (new purchaseId) must work.
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $service = $this->service(apple: $apple);
        $user = $this->makeUser(13, 'NEW', [
            'subscription' => [
                'source' => 'apple',
                'status' => 'expired',
                'subscription_end' => time() - 3600,
                'original_transaction_id' => 'old-apple-tx',
            ],
        ]);

        $result = $service->redeem($user, IapPlatform::APPLE, 'signed-jws');

        $this->assertTrue($result->granted);
        $this->assertSame('PRO', $user->getUserLevel());
        $this->assertSame(self::APPLE_TX, $user->getPaymentDetails()['subscription']['original_transaction_id']);
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

    public function testRedeemRemembersThePurchaseOutsideTheSubscriptionRecord(): void
    {
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, signedAt: 1_700_000_000));
        $user = $this->makeUser(20);

        $this->service(apple: $apple)->redeem($user, IapPlatform::APPLE, 'signed-jws');

        $this->assertSame(
            [['original_transaction_id' => self::APPLE_TX, 'status' => 'active', 'at' => 1_700_000_000]],
            $user->getPaymentDetails()[IapPurchaseHistory::KEY],
        );
    }

    /**
     * The transaction a device holds was signed before the refund and keeps
     * verifying. Once the store has reported the refund it must not grant again.
     */
    public function testReceiptSignedBeforeARefundIsRefused(): void
    {
        $refundedAt = time() - 600;
        $user = $this->makeUser(21, 'PRO', [
            'subscription' => ['source' => 'apple', 'status' => 'active', 'original_transaction_id' => self::APPLE_TX],
        ]);
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, signedAt: $refundedAt - 86400));
        $service = $this->service(apple: $apple, ownerByPurchaseId: $user);

        $service->applyNotification(
            $this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, IapEntitlementState::REVOKED, signedAt: $refundedAt),
        );
        $this->assertSame('NEW', $user->getUserLevel());

        try {
            $service->redeem($user, IapPlatform::APPLE, 'signed-jws');
            $this->fail('Expected IapVerificationException');
        } catch (IapVerificationException) {
            $this->assertSame('NEW', $user->getUserLevel(), 'a refunded purchase must not grant again');
            $this->assertSame('refunded', $user->getPaymentDetails()['subscription']['status']);
        }
    }

    public function testPurchaseMadeAfterARefundIsGranted(): void
    {
        $refundedAt = time() - 600;
        $user = $this->makeUser(22, 'NEW', [
            'subscription' => ['source' => 'apple', 'status' => 'refunded', 'original_transaction_id' => self::APPLE_TX],
            IapPurchaseHistory::KEY => [
                ['original_transaction_id' => self::APPLE_TX, 'status' => 'refunded', 'at' => $refundedAt],
            ],
        ]);
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, signedAt: $refundedAt + 60));

        $result = $this->service(apple: $apple, ownerByPurchaseId: $user)->redeem($user, IapPlatform::APPLE, 'signed-jws');

        $this->assertTrue($result->granted);
        $this->assertSame('PRO', $user->getUserLevel());
    }

    /**
     * A Stripe subscription replaces the subscription record. The refund of
     * the earlier store purchase has to outlive that.
     */
    public function testRefundIsRememberedAfterAStripeSubscriptionReplacedTheRecord(): void
    {
        $refundedAt = time() - 600;
        $user = $this->makeUser(23, 'NEW', [
            'subscription' => [
                'source' => 'stripe',
                'status' => 'canceled',
                'subscription_end' => time() - 60,
                'stripe_subscription_id' => 'sub_old',
            ],
            IapPurchaseHistory::KEY => [
                ['original_transaction_id' => self::APPLE_TX, 'status' => 'refunded', 'at' => $refundedAt],
            ],
        ]);
        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, signedAt: $refundedAt - 3600));

        $this->expectException(IapVerificationException::class);
        $this->service(apple: $apple, ownerByPurchaseId: $user)->redeem($user, IapPlatform::APPLE, 'signed-jws');
    }

    /**
     * Refunds stored before the purchase history existed name only the
     * refunded billing period.
     */
    public function testRefundRecordedBeforeTheHistoryExistedStillBlocksTheSameReceipt(): void
    {
        $periodEnd = time() + 86400;
        $details = [
            'subscription' => [
                'source' => 'apple',
                'status' => 'refunded',
                'subscription_end' => $periodEnd,
                'original_transaction_id' => self::APPLE_TX,
            ],
        ];

        $refunded = $this->makeUser(24, 'NEW', $details);
        $sameReceipt = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, expiresAt: $periodEnd));
        try {
            $this->service(apple: $sameReceipt, ownerByPurchaseId: $refunded)->redeem($refunded, IapPlatform::APPLE, 'signed-jws');
            $this->fail('Expected IapVerificationException');
        } catch (IapVerificationException) {
            $this->assertSame('NEW', $refunded->getUserLevel());
        }

        $resubscribed = $this->makeUser(25, 'NEW', $details);
        $laterPurchase = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, expiresAt: $periodEnd + 86400 * 30));
        $result = $this->service(apple: $laterPurchase, ownerByPurchaseId: $resubscribed)->redeem($resubscribed, IapPlatform::APPLE, 'signed-jws');
        $this->assertTrue($result->granted);
    }

    public function testRefundNotificationIsRememberedWhileAnotherChannelOwnsTheRecord(): void
    {
        $user = $this->makeUser(26, 'PRO', [
            'subscription' => [
                'source' => 'stripe',
                'status' => 'active',
                'subscription_end' => time() + 3600,
                'stripe_subscription_id' => 'sub_live',
            ],
            IapPurchaseHistory::KEY => [
                ['original_transaction_id' => self::APPLE_TX, 'status' => 'active', 'at' => 1_700_000_000],
            ],
        ]);
        $service = $this->service(ownerByPurchaseId: $user);

        $handled = $service->applyNotification(
            $this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX, IapEntitlementState::REVOKED, signedAt: 1_700_000_500),
        );

        $this->assertFalse($handled);
        $this->assertSame('stripe', $user->getPaymentDetails()['subscription']['source'], 'the Stripe record stays untouched');
        $this->assertSame('PRO', $user->getUserLevel());
        $this->assertSame(
            [['original_transaction_id' => self::APPLE_TX, 'status' => 'refunded', 'at' => 1_700_000_500]],
            $user->getPaymentDetails()[IapPurchaseHistory::KEY],
        );
    }

    /**
     * Two accounts presenting one receipt at the same moment must not both
     * pass the ownership check: it runs under a lock named after the purchase.
     */
    public function testOwnershipCheckRunsUnderALockOnThePurchase(): void
    {
        $locks = new LockFactory(new InMemoryStore());
        $lockName = 'iap-redeem-'.hash('sha256', 'apple:'.self::APPLE_TX);
        $heldDuringCheck = null;

        $repo = $this->createStub(UserRepository::class);
        $repo->method('findByIapPurchaseId')->willReturnCallback(
            static function () use ($locks, $lockName, &$heldDuringCheck): ?User {
                $heldDuringCheck = !$locks->createLock($lockName)->acquire();

                return null;
            },
        );

        $apple = new FakeAppleVerifier($this->entitlement(IapPlatform::APPLE, 'app.pro', self::APPLE_TX));
        $this->service(apple: $apple, repo: $repo, locks: $locks)->redeem($this->makeUser(27), IapPlatform::APPLE, 'signed-jws');

        $this->assertTrue($heldDuringCheck, 'the ownership check must hold the purchase lock');
        $this->assertTrue($locks->createLock($lockName)->acquire(), 'the lock is released after the redeem');
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function service(
        ?FakeAppleVerifier $apple = null,
        ?FakeGoogleVerifier $google = null,
        ?User $ownerByPurchaseId = null,
        ?UserRepository $repo = null,
        ?LockFactory $locks = null,
    ): MobilePurchaseService {
        $em = $this->createStub(EntityManagerInterface::class);

        if (null === $repo) {
            $repo = $this->createStub(UserRepository::class);
            $repo->method('findByIapPurchaseId')->willReturn($ownerByPurchaseId);
        }

        return new MobilePurchaseService(
            $em,
            $repo,
            new IapPricingService('app.pro', 'app.team', 'app.business'),
            $apple ?? new FakeAppleVerifier(null),
            $google ?? new FakeGoogleVerifier(null),
            new NullLogger(),
            $this->levelGrants(),
            $locks ?? new LockFactory(new InMemoryStore()),
            new IapPurchaseHistory(),
        );
    }

    private function entitlement(
        IapPlatform $platform,
        string $productId,
        string $purchaseId,
        IapEntitlementState $state = IapEntitlementState::ACTIVE,
        ?int $signedAt = null,
        ?int $expiresAt = null,
    ): IapEntitlement {
        return new IapEntitlement(
            platform: $platform,
            productId: $productId,
            purchaseId: $purchaseId,
            state: $state,
            expiresAt: $expiresAt ?? time() + 86400,
            autoRenew: true,
            environment: 'sandbox',
            signedAt: $signedAt,
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
