<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\SignupAdmission;
use App\Service\Auth\SignupBlockedException;
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
        $users->expects($this->never())->method('findOneBy');
        $admission = $this->createMock(SignupAdmission::class);
        $admission->method('resolve')->willThrowException(SignupBlockedException::admissionRequired());

        $this->expectException(SignupBlockedException::class);
        $this->service($admission, $users)->createSignup('known@example.com', 'secret', [], null, null, '203.0.113.9');
    }

    public function testAnExistingAddressStillGetsTheSameAnswer(): void
    {
        $existing = $this->createMock(User::class);
        $users = $this->createMock(UserRepository::class);
        $users->method('findOneBy')->with(['mail' => 'known@example.com'])->willReturn($existing);
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
        );

        self::assertNull($service->createSignup('known@example.com', 'secret', [], 'PRO', null, '203.0.113.9'));
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
        );
    }
}
