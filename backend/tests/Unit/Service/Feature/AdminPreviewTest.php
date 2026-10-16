<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Feature;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Feature\AdminPreview;
use PHPUnit\Framework\TestCase;

final class AdminPreviewTest extends TestCase
{
    private const FEATURE = 'test-preview';

    public function testAnAdminIsAllowed(): void
    {
        $preview = $this->preview(null);

        $this->assertTrue($preview->allows(self::FEATURE, $this->user('ADMIN')));
    }

    public function testAnOidcAdminIsAllowedWithoutTheInternalAdminLevel(): void
    {
        $user = $this->user('NEW');
        $user->setUserDetails(['oidc_roles' => ['admin']]);

        $this->assertTrue($this->preview(null)->allows(self::FEATURE, $user));
    }

    public function testARegularUserAndAnAnonymousCallerAreRefused(): void
    {
        $preview = $this->preview(null);

        $this->assertFalse($preview->allows(self::FEATURE, $this->user('NEW')));
        $this->assertFalse($preview->allows(self::FEATURE, null));
    }

    public function testAllowsUserIdLoadsTheOwner(): void
    {
        $admin = $this->user('ADMIN');
        $member = $this->user('PRO');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturnCallback(static fn (int $id): ?User => match ($id) {
            7 => $admin,
            8 => $member,
            default => null,
        });
        $preview = new AdminPreview($users, [self::FEATURE]);

        $this->assertTrue($preview->allowsUserId(self::FEATURE, 7));
        $this->assertFalse($preview->allowsUserId(self::FEATURE, 8));
        $this->assertFalse($preview->allowsUserId(self::FEATURE, 9));
    }

    public function testAnUnknownFeatureFailsLoudly(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('nope');

        $this->preview(null)->allows('nope', $this->user('ADMIN'));
    }

    private function preview(?User $loaded): AdminPreview
    {
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($loaded);

        return new AdminPreview($users, [self::FEATURE]);
    }

    private function user(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }
}
