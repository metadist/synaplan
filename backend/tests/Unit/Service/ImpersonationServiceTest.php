<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\ImpersonationService;
use App\Service\TokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Unit tests for {@see ImpersonationService}.
 *
 * Focus areas:
 *   - Security invariants on start (you can't impersonate yourself, another
 *     admin, nest, or use OIDC sessions).
 *   - The single-stash cookie mechanics: only the admin's refresh token is
 *     stashed; no extra refresh token is ever minted for the impersonated
 *     user; the regular refresh cookie is explicitly cleared.
 *   - The impersonation-aware refresh path that mints fresh
 *     `impersonator_id`-claimed access tokens for the target without
 *     touching any refresh token.
 *
 * Token generation/encoding itself is covered by TokenServiceTest; here we
 * mock TokenService so we can assert how the impersonation service drives it.
 */
final class ImpersonationServiceTest extends TestCase
{
    private TokenService&MockObject $tokenService;
    private UserRepository&MockObject $userRepository;
    private ImpersonationService $service;

    protected function setUp(): void
    {
        $this->tokenService = $this->createMock(TokenService::class);
        $this->userRepository = $this->createMock(UserRepository::class);

        $this->service = new ImpersonationService(
            $this->tokenService,
            $this->userRepository,
            new NullLogger(),
            'test',
        );
    }

    // ---------------------------------------------------------------------
    // start() — security invariants
    // ---------------------------------------------------------------------

    public function testStartImpersonationRefusesNonAdmin(): void
    {
        $caller = $this->makeUser(id: 5, level: 'PRO');
        $target = $this->makeUser(id: 6, level: 'NEW');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Only administrators');

        $this->service->startImpersonation($caller, $target, $this->requestWithAppTokens(), new Response());
    }

    public function testStartImpersonationRefusesSelfImpersonation(): void
    {
        $admin = $this->makeUser(id: 1, level: 'ADMIN');

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('yourself');

        $this->service->startImpersonation($admin, $admin, $this->requestWithAppTokens(), new Response());
    }

    public function testStartImpersonationAllowsAnotherAdminTarget(): void
    {
        // Admin-on-admin impersonation is intentionally permitted: handover,
        // peer debugging, etc. The audit log captures both ids so the action
        // remains attributable. The only guard against cross-admin abuse is
        // the start/stop warning log + the hard self-impersonation refusal.
        $admin = $this->makeUser(id: 1, level: 'ADMIN');
        $otherAdmin = $this->makeUser(id: 2, level: 'ADMIN');

        $request = $this->requestWithAppTokens([
            TokenService::REFRESH_COOKIE => 'admin-refresh',
        ]);
        $response = new Response();

        $this->tokenService
            ->expects(self::once())
            ->method('generateAccessToken')
            ->with($otherAdmin, 1)
            ->willReturn('other-admin-impersonation-access');

        $this->tokenService
            ->method('createAccessCookie')
            ->willReturn(Cookie::create(TokenService::ACCESS_COOKIE)->withValue('other-admin-impersonation-access'));

        $this->tokenService
            ->expects(self::once())
            ->method('createClearRefreshCookie')
            ->willReturn(Cookie::create(TokenService::REFRESH_COOKIE)->withValue(''));

        $this->service->startImpersonation($admin, $otherAdmin, $request, $response);

        $cookies = $this->indexCookies($response);
        self::assertSame(
            'admin-refresh',
            $cookies[ImpersonationService::ADMIN_REFRESH_STASH_COOKIE]->getValue(),
            'admin refresh token must still be stashed when impersonating another admin'
        );
        self::assertSame(
            'other-admin-impersonation-access',
            $cookies[TokenService::ACCESS_COOKIE]->getValue()
        );
    }

    public function testStartImpersonationRefusesNestedImpersonation(): void
    {
        $admin = $this->makeUser(id: 1, level: 'ADMIN');
        $target = $this->makeUser(id: 6, level: 'NEW');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'already-stashed',
        ]);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Already impersonating');

        $this->service->startImpersonation($admin, $target, $request, new Response());
    }

    public function testStartImpersonationRefusesOidcSession(): void
    {
        $admin = $this->makeUser(id: 1, level: 'ADMIN');
        $target = $this->makeUser(id: 6, level: 'NEW');

        // No app-token refresh cookie on the request — emulates a Keycloak
        // session.
        $request = new Request();

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('OIDC');

        $this->service->startImpersonation($admin, $target, $request, new Response());
    }

    // ---------------------------------------------------------------------
    // start() — happy path & cookie mechanics
    // ---------------------------------------------------------------------

    public function testStartImpersonationStashesAdminRefreshAndIssuesImpersonationAccessTokenOnly(): void
    {
        $admin = $this->makeUser(id: 1, level: 'ADMIN');
        $target = $this->makeUser(id: 7, level: 'PRO');

        $request = $this->requestWithAppTokens([
            TokenService::REFRESH_COOKIE => 'admin-refresh',
        ]);
        $response = new Response();

        // The impersonation access token must carry the admin id as
        // `impersonator_id` claim — that is the single source of truth for
        // both the banner and the refresh path.
        $this->tokenService
            ->expects(self::once())
            ->method('generateAccessToken')
            ->with($target, 1)
            ->willReturn('target-impersonation-access');

        // Crucially, NO refresh token is minted for the target.
        $this->tokenService
            ->expects(self::never())
            ->method('generateRefreshToken');

        $this->tokenService
            ->method('createAccessCookie')
            ->with('target-impersonation-access')
            ->willReturn(Cookie::create(TokenService::ACCESS_COOKIE)->withValue('target-impersonation-access'));

        $this->tokenService
            ->expects(self::once())
            ->method('createClearRefreshCookie')
            ->willReturn(Cookie::create(TokenService::REFRESH_COOKIE)->withValue(''));

        $this->service->startImpersonation($admin, $target, $request, $response);

        $cookies = $this->indexCookies($response);

        self::assertSame(
            'admin-refresh',
            $cookies[ImpersonationService::ADMIN_REFRESH_STASH_COOKIE]->getValue(),
            'admin refresh token must land in the stash cookie verbatim'
        );
        self::assertTrue(
            $cookies[ImpersonationService::ADMIN_REFRESH_STASH_COOKIE]->isHttpOnly(),
            'stash cookie must be HttpOnly'
        );
        self::assertSame(
            'target-impersonation-access',
            $cookies[TokenService::ACCESS_COOKIE]->getValue()
        );
        self::assertSame(
            '',
            $cookies[TokenService::REFRESH_COOKIE]->getValue(),
            'regular refresh cookie must be explicitly cleared on impersonation start'
        );
    }

    // ---------------------------------------------------------------------
    // stop()
    // ---------------------------------------------------------------------

    public function testStopImpersonationRestoresAdminCookiesViaRefreshTokenAndIssuesFreshAccessToken(): void
    {
        // Exit must work even after the 5-minute access TTL has elapsed
        // during a long impersonation. We rely solely on the refresh token +
        // a freshly minted access token.
        $admin = $this->makeUser(id: 1, level: 'ADMIN');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stashed-admin-refresh',
        ]);
        $response = new Response();

        $refreshTokenEntity = $this->createMock(\App\Entity\Token::class);
        $refreshTokenEntity->method('getUser')->willReturn($admin);

        $this->tokenService
            ->expects(self::once())
            ->method('validateRefreshToken')
            ->with('stashed-admin-refresh')
            ->willReturn($refreshTokenEntity);

        $this->tokenService
            ->expects(self::once())
            ->method('generateAccessToken')
            ->with($admin)
            ->willReturn('fresh-admin-access');

        $this->tokenService
            ->method('createAccessCookie')
            ->with('fresh-admin-access')
            ->willReturn(Cookie::create(TokenService::ACCESS_COOKIE)->withValue('fresh-admin-access'));

        $this->tokenService
            ->method('createRefreshCookie')
            ->with('stashed-admin-refresh')
            ->willReturn(Cookie::create(TokenService::REFRESH_COOKIE)->withValue('stashed-admin-refresh'));

        $restored = $this->service->stopImpersonation($request, $response);

        self::assertSame($admin, $restored);

        $cookies = $this->indexCookies($response);

        self::assertSame('fresh-admin-access', $cookies[TokenService::ACCESS_COOKIE]->getValue());
        self::assertSame('stashed-admin-refresh', $cookies[TokenService::REFRESH_COOKIE]->getValue());
        self::assertSame(
            '',
            $cookies[ImpersonationService::ADMIN_REFRESH_STASH_COOKIE]->getValue(),
            'stash cookie must be cleared on exit'
        );
    }

    public function testStopImpersonationFailsWhenNoStash(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('No active impersonation');

        $this->service->stopImpersonation(new Request(), new Response());
    }

    public function testStopImpersonationFailsWhenRefreshTokenInvalid(): void
    {
        // Tampered or revoked refresh token — exit must refuse cleanly and
        // wipe both the stash and the regular auth cookies so the browser
        // ends up in a clean unauthenticated state.
        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'tampered-refresh',
        ]);
        $response = new Response();

        $this->tokenService
            ->expects(self::once())
            ->method('validateRefreshToken')
            ->willReturn(null);

        $this->tokenService
            ->expects(self::once())
            ->method('clearAuthCookies')
            ->with($response)
            ->willReturn($response);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('expired');

        $this->service->stopImpersonation($request, $response);
    }

    public function testStopImpersonationFailsWhenStashedAdminLostRole(): void
    {
        // The refresh token still validates, but the user has been demoted
        // since impersonation started — we must NOT restore an admin session.
        $exAdmin = $this->makeUser(id: 1, level: 'PRO');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stashed-refresh',
        ]);
        $response = new Response();

        $refreshTokenEntity = $this->createMock(\App\Entity\Token::class);
        $refreshTokenEntity->method('getUser')->willReturn($exAdmin);

        $this->tokenService
            ->method('validateRefreshToken')
            ->willReturn($refreshTokenEntity);

        $this->tokenService
            ->expects(self::once())
            ->method('clearAuthCookies')
            ->with($response)
            ->willReturn($response);

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('expired');

        $this->service->stopImpersonation($request, $response);
    }

    // ---------------------------------------------------------------------
    // issueRefreshedImpersonationAccessToken()
    // ---------------------------------------------------------------------

    public function testRefreshIssuesFreshImpersonationAccessTokenForTarget(): void
    {
        $admin = $this->makeUser(id: 1, level: 'ADMIN');
        $target = $this->makeUser(id: 7, level: 'PRO');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stashed-admin-refresh',
            TokenService::ACCESS_COOKIE => 'expired-target-access',
        ]);

        $refreshTokenEntity = $this->createMock(\App\Entity\Token::class);
        $refreshTokenEntity->method('getUser')->willReturn($admin);

        $this->tokenService
            ->method('validateRefreshToken')
            ->with('stashed-admin-refresh')
            ->willReturn($refreshTokenEntity);

        $this->tokenService
            ->expects(self::once())
            ->method('decodeAccessTokenIgnoringExpiry')
            ->with('expired-target-access')
            ->willReturn([
                'user_id' => 7,
                'impersonator_id' => 1,
                'type' => 'access',
            ]);

        $this->userRepository
            ->method('find')
            ->with(7)
            ->willReturn($target);

        $this->tokenService
            ->expects(self::once())
            ->method('generateAccessToken')
            ->with($target, 1)
            ->willReturn('refreshed-target-access');

        $result = $this->service->issueRefreshedImpersonationAccessToken($request);

        self::assertNotNull($result);
        self::assertSame('refreshed-target-access', $result['access_token']);
        self::assertSame($target, $result['user']);
    }

    public function testRefreshReturnsNullWhenNoStash(): void
    {
        self::assertNull(
            $this->service->issueRefreshedImpersonationAccessToken(new Request())
        );
    }

    public function testRefreshReturnsNullWhenStashRefreshInvalid(): void
    {
        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'tampered',
        ]);

        $this->tokenService
            ->method('validateRefreshToken')
            ->willReturn(null);

        self::assertNull(
            $this->service->issueRefreshedImpersonationAccessToken($request)
        );
    }

    public function testRefreshReturnsNullWhenAccessCookieMissing(): void
    {
        $admin = $this->makeUser(id: 1, level: 'ADMIN');

        // Simulate a request that has the stash but no access cookie at all
        // (e.g. cleared by a misbehaving client). We must not issue a token
        // we can't tie back to a target user.
        $request = new Request(cookies: [
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stashed-refresh',
        ]);

        $refreshTokenEntity = $this->createMock(\App\Entity\Token::class);
        $refreshTokenEntity->method('getUser')->willReturn($admin);

        $this->tokenService
            ->method('validateRefreshToken')
            ->willReturn($refreshTokenEntity);

        self::assertNull(
            $this->service->issueRefreshedImpersonationAccessToken($request)
        );
    }

    public function testRefreshReturnsNullWhenClaimAndStashAdminDisagree(): void
    {
        // The stash unlocks admin#1, but the access token says it was minted
        // by admin#999. Either is forged or we hit a logic bug — refuse.
        $admin = $this->makeUser(id: 1, level: 'ADMIN');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stashed-refresh',
            TokenService::ACCESS_COOKIE => 'mismatched-access',
        ]);

        $refreshTokenEntity = $this->createMock(\App\Entity\Token::class);
        $refreshTokenEntity->method('getUser')->willReturn($admin);

        $this->tokenService
            ->method('validateRefreshToken')
            ->willReturn($refreshTokenEntity);

        $this->tokenService
            ->method('decodeAccessTokenIgnoringExpiry')
            ->willReturn([
                'user_id' => 7,
                'impersonator_id' => 999,
                'type' => 'access',
            ]);

        $this->tokenService
            ->expects(self::never())
            ->method('generateAccessToken');

        self::assertNull(
            $this->service->issueRefreshedImpersonationAccessToken($request)
        );
    }

    public function testRefreshSucceedsWhenTargetIsAdmin(): void
    {
        // Admin-on-admin impersonation is allowed at start time, so the
        // refresh path must keep the session alive for an admin target —
        // including one that was promoted mid-session. The impersonator
        // claim plus stash refresh token still uniquely identify the
        // operating admin, so attribution is not lost.
        $admin = $this->makeUser(id: 1, level: 'ADMIN');
        $adminTarget = $this->makeUser(id: 7, level: 'ADMIN');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stashed-refresh',
            TokenService::ACCESS_COOKIE => 'target-access',
        ]);

        $refreshTokenEntity = $this->createMock(\App\Entity\Token::class);
        $refreshTokenEntity->method('getUser')->willReturn($admin);

        $this->tokenService
            ->method('validateRefreshToken')
            ->willReturn($refreshTokenEntity);

        $this->tokenService
            ->method('decodeAccessTokenIgnoringExpiry')
            ->willReturn([
                'user_id' => 7,
                'impersonator_id' => 1,
                'type' => 'access',
            ]);

        $this->userRepository
            ->method('find')
            ->willReturn($adminTarget);

        $this->tokenService
            ->expects(self::once())
            ->method('generateAccessToken')
            ->with($adminTarget, 1)
            ->willReturn('refreshed-admin-target-access');

        $result = $this->service->issueRefreshedImpersonationAccessToken($request);

        self::assertNotNull($result);
        self::assertSame('refreshed-admin-target-access', $result['access_token']);
        self::assertSame($adminTarget, $result['user']);
    }

    // ---------------------------------------------------------------------
    // resolveImpersonatorFromActiveSession()
    // ---------------------------------------------------------------------

    public function testResolveImpersonatorReturnsNullWhenNoStash(): void
    {
        self::assertNull($this->service->resolveImpersonatorFromActiveSession(new Request()));
    }

    public function testResolveImpersonatorReadsAdminFromAccessTokenClaim(): void
    {
        // The banner reads off the active access token's impersonator_id
        // claim. The stash cookie also has to be present (defence in depth)
        // but its content is not consulted here.
        $admin = $this->makeUser(id: 1, level: 'ADMIN');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stash-presence-only',
            TokenService::ACCESS_COOKIE => 'target-access',
        ]);

        $this->tokenService
            ->expects(self::once())
            ->method('decodeAccessTokenIgnoringExpiry')
            ->with('target-access')
            ->willReturn([
                'user_id' => 7,
                'impersonator_id' => 1,
                'type' => 'access',
            ]);

        $this->userRepository
            ->method('find')
            ->with(1)
            ->willReturn($admin);

        self::assertSame($admin, $this->service->resolveImpersonatorFromActiveSession($request));
    }

    public function testResolveImpersonatorReturnsNullWhenStashMissingButClaimPresent(): void
    {
        // Defence in depth: an access token can technically outlive its
        // stash (e.g. logout cleared the stash but a stale cookie lingers).
        // In that case we refuse to render the banner.
        $request = $this->requestWithAppTokens([
            TokenService::ACCESS_COOKIE => 'target-access',
        ]);

        $this->tokenService
            ->method('decodeAccessTokenIgnoringExpiry')
            ->willReturn([
                'user_id' => 7,
                'impersonator_id' => 1,
                'type' => 'access',
            ]);

        // userRepository must NOT be queried — we short-circuit before that.
        $this->userRepository
            ->expects(self::never())
            ->method('find');

        self::assertNull($this->service->resolveImpersonatorFromActiveSession($request));
    }

    public function testResolveImpersonatorReturnsNullWhenStashedUserIsNoLongerAdmin(): void
    {
        $exAdmin = $this->makeUser(id: 1, level: 'PRO');

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'stash',
            TokenService::ACCESS_COOKIE => 'target-access',
        ]);

        $this->tokenService
            ->method('decodeAccessTokenIgnoringExpiry')
            ->willReturn([
                'user_id' => 7,
                'impersonator_id' => 1,
                'type' => 'access',
            ]);

        $this->userRepository
            ->method('find')
            ->willReturn($exAdmin);

        self::assertNull($this->service->resolveImpersonatorFromActiveSession($request));
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    public function testAttachClearStashCookiesEmitsExpiredStashCookie(): void
    {
        $response = new Response();
        $this->service->attachClearStashCookies($response);

        $cookies = $this->indexCookies($response);

        self::assertArrayHasKey(ImpersonationService::ADMIN_REFRESH_STASH_COOKIE, $cookies);
        self::assertSame('', $cookies[ImpersonationService::ADMIN_REFRESH_STASH_COOKIE]->getValue());
    }

    public function testIsImpersonatingReflectsStashCookiePresence(): void
    {
        self::assertFalse($this->service->isImpersonating(new Request()));

        $request = $this->requestWithAppTokens([
            ImpersonationService::ADMIN_REFRESH_STASH_COOKIE => 'something',
        ]);

        self::assertTrue($this->service->isImpersonating($request));
    }

    /**
     * @param array<string, string> $extraCookies merged on top of the default app-token pair
     */
    private function requestWithAppTokens(array $extraCookies = []): Request
    {
        return new Request(
            cookies: array_merge(
                [
                    TokenService::ACCESS_COOKIE => 'admin-access',
                    TokenService::REFRESH_COOKIE => 'admin-refresh',
                ],
                $extraCookies,
            )
        );
    }

    private function makeUser(int $id, string $level): User
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn($id);
        $user->method('getMail')->willReturn(sprintf('user-%d@example.com', $id));
        $user->method('getUserLevel')->willReturn($level);
        $user->method('isAdmin')->willReturn('ADMIN' === $level);

        return $user;
    }

    /**
     * @return array<string, Cookie>
     */
    private function indexCookies(Response $response): array
    {
        $indexed = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $indexed[$cookie->getName()] = $cookie;
        }

        return $indexed;
    }
}
