<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\User;
use App\Repository\OAuthTokenRepository;
use App\Repository\UserRepository;
use App\Service\Auth\RegistrationConfig;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
use App\Service\Auth\SignupDoor;
use App\Service\Auth\TokenService;
use App\Service\Iam\UserLifecycleService;
use App\Service\ModelConfigService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserLifecycleSignupTest extends TestCase
{
    public function testAdmissionIsCheckedBeforeTheAddressIsLookedUp(): void
    {
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->never())->method('findOtherByNormalizedEmail');
        $admission = $this->createMock(SignupAdmission::class);
        $admission->method('resolve')->willThrowException(SignupBlockedException::admissionRequired());

        $this->expectException(SignupBlockedException::class);
        $this->service($admission, $users)->createSignup('known@example.com', 'secret', [], null, null, '203.0.113.9');
    }

    public function testAnExistingAddressStillGetsTheSameAnswer(): void
    {
        $existing = $this->createMock(User::class);
        $users = $this->createMock(UserRepository::class);
        $users->expects($this->once())->method('findOtherByNormalizedEmail')->with('known@example.com', 0)->willReturn($existing);
        $hasher = $this->createMock(UserPasswordHasherInterface::class);
        $hasher->expects($this->once())->method('hashPassword')->with($existing, 'secret');
        $admission = $this->createMock(SignupAdmission::class);
        $admission->method('resolve')->willReturn(null);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $service = new UserLifecycleService(
            $em,
            $hasher,
            $this->createMock(ModelConfigService::class),
            new NullLogger(),
            $admission,
            $users,
            $this->createStub(TokenService::class),
            $this->registration(),
            $this->createStub(OAuthTokenRepository::class),
        );

        self::assertNull($service->createSignup('known@example.com', 'secret', [], 'PRO', null, '203.0.113.9'));
    }

    public function testASocialDoorBuildsAnUnsavedAccountOnlyWhileSignUpIsOpen(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');
        $open = new UserLifecycleService(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(ModelConfigService::class),
            new NullLogger(),
            $this->createMock(SignupAdmission::class),
            $this->createMock(UserRepository::class),
            $this->createStub(TokenService::class),
            $this->registration(true),
            $this->createStub(OAuthTokenRepository::class),
        );

        $user = $open->buildExternalAccount(SignupDoor::Google, 'new@example.com', true);
        self::assertSame('google', $user->getProviderId());
        self::assertTrue($user->isEmailVerified());

        $closed = new UserLifecycleService(
            $em,
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(ModelConfigService::class),
            new NullLogger(),
            $this->createMock(SignupAdmission::class),
            $this->createMock(UserRepository::class),
            $this->createStub(TokenService::class),
            $this->registration(false),
            $this->createStub(OAuthTokenRepository::class),
        );
        $this->expectException(SignupBlockedException::class);
        $closed->buildExternalAccount(SignupDoor::Apple, 'new@example.com', true);
    }

    private function service(SignupAdmission $admission, UserRepository $users): UserLifecycleService
    {
        return new UserLifecycleService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(UserPasswordHasherInterface::class),
            $this->createMock(ModelConfigService::class),
            new NullLogger(),
            $admission,
            $users,
            $this->createStub(TokenService::class),
            $this->registration(),
            $this->createStub(OAuthTokenRepository::class),
        );
    }

    private function registration(bool $enabled = true): RegistrationConfig
    {
        $registration = $this->createMock(RegistrationConfig::class);
        $registration->method('isEnabled')->willReturn($enabled);

        return $registration;
    }
}
