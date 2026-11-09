<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Auth;

use App\Entity\User;
use App\Service\Auth\ImpersonationService;
use App\Service\Auth\ProviderDiagnosticsAccess;
use App\Service\Auth\TokenService;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * The provider dump is stored for every failure. It is returned to an
 * administrator, and to an administrator who is impersonating that account.
 * The account itself must not receive it, and a claim without the admin's
 * still-valid stash must not either.
 */
final class ProviderDiagnosticsAccessTest extends TestCase
{
    private Security&Stub $security;
    private ImpersonationService&Stub $impersonation;
    private RequestStack $requests;
    private ProviderDiagnosticsAccess $access;

    protected function setUp(): void
    {
        $this->security = $this->createStub(Security::class);
        $this->impersonation = $this->createStub(ImpersonationService::class);
        $this->requests = new RequestStack();
        $this->access = new ProviderDiagnosticsAccess($this->security, $this->impersonation, $this->requests);
    }

    public function testAnonymousViewerSeesNothing(): void
    {
        $this->security->method('getUser')->willReturn(null);

        self::assertFalse($this->access->viewerMaySee());
    }

    public function testAdministratorOnTheirOwnSessionSeesTheDump(): void
    {
        $this->security->method('getUser')->willReturn($this->user(1, admin: true));

        self::assertTrue($this->access->viewerMaySee());
    }

    public function testAccountOwnerDoesNotSeeTheDump(): void
    {
        $this->security->method('getUser')->willReturn($this->user(7, admin: false));
        $impersonation = $this->createMock(ImpersonationService::class);
        $impersonation->expects(self::never())->method('resolveStashedAdmin');
        $access = new ProviderDiagnosticsAccess($this->security, $impersonation, $this->requests);

        self::assertFalse($access->viewerMaySee());
    }

    public function testImpersonatingAdministratorSeesTheDumpForThatAccount(): void
    {
        $target = $this->user(7, admin: false);
        $this->security->method('getUser')->willReturn($target);
        $this->pushImpersonation(targetId: 7, adminId: 1);
        $this->impersonation->method('resolveStashedAdmin')->willReturn($this->user(1, admin: true));

        self::assertTrue($this->access->viewerMaySee());
    }

    public function testStashForADifferentAdminDoesNotOpenTheDump(): void
    {
        $this->security->method('getUser')->willReturn($this->user(7, admin: false));
        $this->pushImpersonation(targetId: 7, adminId: 1);
        $this->impersonation->method('resolveStashedAdmin')->willReturn($this->user(2, admin: true));

        self::assertFalse($this->access->viewerMaySee());
    }

    public function testMissingOrRevokedStashDoesNotOpenTheDump(): void
    {
        $this->security->method('getUser')->willReturn($this->user(7, admin: false));
        $this->pushImpersonation(targetId: 7, adminId: 1);
        $this->impersonation->method('resolveStashedAdmin')->willReturn(null);

        self::assertFalse($this->access->viewerMaySee());
    }

    public function testClaimForADifferentAccountDoesNotOpenTheDump(): void
    {
        $this->security->method('getUser')->willReturn($this->user(7, admin: false));
        $this->pushImpersonation(targetId: 8, adminId: 1);
        $impersonation = $this->createMock(ImpersonationService::class);
        $impersonation->expects(self::never())->method('resolveStashedAdmin');
        $access = new ProviderDiagnosticsAccess($this->security, $impersonation, $this->requests);

        self::assertFalse($access->viewerMaySee());
    }

    public function testStringClaimIsIgnored(): void
    {
        $this->security->method('getUser')->willReturn($this->user(7, admin: false));
        $request = Request::create('/');
        $request->attributes->set('access_token_user_id', 7);
        $request->attributes->set(TokenService::IMPERSONATOR_ATTRIBUTE, '1');
        $this->requests->push($request);
        $impersonation = $this->createMock(ImpersonationService::class);
        $impersonation->expects(self::never())->method('resolveStashedAdmin');
        $access = new ProviderDiagnosticsAccess($this->security, $impersonation, $this->requests);

        self::assertFalse($access->viewerMaySee());
    }

    private function pushImpersonation(int $targetId, int $adminId): void
    {
        $request = Request::create('/');
        $request->attributes->set('access_token_user_id', $targetId);
        $request->attributes->set(TokenService::IMPERSONATOR_ATTRIBUTE, $adminId);
        $this->requests->push($request);
    }

    private function user(int $id, bool $admin): User&Stub
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('isAdmin')->willReturn($admin);

        return $user;
    }
}
