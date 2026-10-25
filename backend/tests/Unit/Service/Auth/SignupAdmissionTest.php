<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\SignupCodeRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SignupAdmissionTest extends TestCase
{
    public function testOpenModeIgnoresPlanAndCode(): void
    {
        $registration = $this->createMock(RegistrationConfig::class);
        $registration->method('isAdmissionMode')->willReturn(false);

        $admission = new SignupAdmission($registration, $this->createMock(SignupCodeRepository::class), $this->createMock(EntityManagerInterface::class));

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

    private function admission(bool $admissionMode): SignupAdmission
    {
        $registration = $this->createMock(RegistrationConfig::class);
        $registration->method('isAdmissionMode')->willReturn($admissionMode);
        $em = $this->createMock(EntityManagerInterface::class);

        return new SignupAdmission($registration, $this->createMock(SignupCodeRepository::class), $em);
    }
}
