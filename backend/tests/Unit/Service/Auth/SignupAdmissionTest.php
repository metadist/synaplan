<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\SignupCodeRepository;
use App\Repository\SubscriptionRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
use App\Service\Billing\BillingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SignupAdmissionTest extends TestCase
{
    public function testOpenModeIgnoresPlanAndCode(): void
    {
        $registration = $this->createMock(RegistrationConfig::class);
        $registration->method('isAdmissionMode')->willReturn(false);

        $admission = $this->admission(false);

        self::assertNull($admission->resolve('PRO', 'ABCDEFGHJKLM'));
        self::assertNull($admission->resolve(null, null));
    }

    public function testAdmissionRequiresExactlyOneOfPlanOrCode(): void
    {
        $admission = $this->admission(true);

        $this->expectException(SignupBlockedException::class);
        $admission->resolve(null, null);
    }

    public function testAPlanWaitsForPayment(): void
    {
        $user = new User();
        $this->admission(true)->apply($user, ['plan' => 'TEAM', 'code' => null]);

        self::assertTrue($user->isPendingPayment());
        self::assertSame('TEAM', $user->getUserDetails()['pending_plan'] ?? null);
    }

    public function testAnUnknownPlanIsRefused(): void
    {
        $this->expectException(SignupBlockedException::class);
        $this->admission(true)->resolve('FREE', null);
    }

    public function testAPlanRequiresCheckout(): void
    {
        $this->expectException(SignupBlockedException::class);
        $this->admission(true, false)->resolve('PRO', null);
    }

    public function testAPurchasablePlanIsAccepted(): void
    {
        $admission = $this->admission(true)->resolve('TEAM', null);

        self::assertIsArray($admission);
        self::assertSame('TEAM', $admission['plan']);
        self::assertNull($admission['code']);
    }

    public function testAHiddenPlanIsRefused(): void
    {
        $hidden = $this->createMock(Subscription::class);
        $hidden->method('isActive')->willReturn(false);
        $subscriptions = $this->createMock(SubscriptionRepository::class);
        $subscriptions->method('findByLevelAnyState')->willReturn($hidden);

        $this->expectException(SignupBlockedException::class);
        $this->admission(true, true, $subscriptions)->resolve('PRO', null);
    }

    private function admission(bool $admissionMode, bool $billingEnabled = true, ?SubscriptionRepository $subscriptions = null): SignupAdmission
    {
        $registration = $this->createMock(RegistrationConfig::class);
        $registration->method('isAdmissionMode')->willReturn($admissionMode);
        $billing = $this->createMock(BillingService::class);
        $billing->method('isEnabled')->willReturn($billingEnabled);
        $subscriptions ??= $this->createMock(SubscriptionRepository::class);

        return new SignupAdmission(
            $registration,
            $this->createMock(SignupCodeRepository::class),
            $this->createMock(EntityManagerInterface::class),
            $billing,
            $subscriptions,
        );
    }
}
