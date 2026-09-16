<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Auth\DemoLoginHint;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class DemoLoginHintTest extends TestCase
{
    private UserRepository&MockObject $users;
    private UserPasswordHasherInterface&MockObject $hasher;

    protected function setUp(): void
    {
        $this->users = $this->createMock(UserRepository::class);
        $this->hasher = $this->createMock(UserPasswordHasherInterface::class);
    }

    public function testHiddenInProductionEvenWhenDefaultPasswordStillWorks(): void
    {
        $this->users->expects($this->never())->method('findByEmail');

        $hint = new DemoLoginHint($this->users, $this->hasher, 'prod');

        $this->assertFalse($hint->isVisible());
    }

    public function testHiddenWhenSeededAdminIsMissing(): void
    {
        $this->users->expects($this->once())
            ->method('findByEmail')
            ->with(DemoLoginHint::EMAIL)
            ->willReturn(null);

        $hint = new DemoLoginHint($this->users, $this->hasher, 'dev');

        $this->assertFalse($hint->isVisible());
    }

    public function testHiddenWhenDefaultPasswordWasChanged(): void
    {
        $admin = new User();
        $this->users->expects($this->once())->method('findByEmail')->willReturn($admin);
        $this->hasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($admin, DemoLoginHint::PASSWORD)
            ->willReturn(false);

        $hint = new DemoLoginHint($this->users, $this->hasher, 'dev');

        $this->assertFalse($hint->isVisible());
    }

    public function testVisibleInDevWhileFixturePasswordStillWorks(): void
    {
        $admin = new User();
        $this->users->expects($this->once())->method('findByEmail')->willReturn($admin);
        $this->hasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($admin, DemoLoginHint::PASSWORD)
            ->willReturn(true);

        $hint = new DemoLoginHint($this->users, $this->hasher, 'dev');

        $this->assertTrue($hint->isVisible());
    }

    public function testVisibleInTestWhileFixturePasswordStillWorks(): void
    {
        $admin = new User();
        $this->users->expects($this->once())->method('findByEmail')->willReturn($admin);
        $this->hasher->expects($this->once())
            ->method('isPasswordValid')
            ->with($admin, DemoLoginHint::PASSWORD)
            ->willReturn(true);

        $hint = new DemoLoginHint($this->users, $this->hasher, 'test');

        $this->assertTrue($hint->isVisible());
    }
}
