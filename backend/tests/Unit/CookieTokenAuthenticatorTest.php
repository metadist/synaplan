<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Security\CookieTokenAuthenticator;
use App\Service\OidcTokenService;
use App\Service\TokenService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Exception\AuthenticationException;

class CookieTokenAuthenticatorTest extends TestCase
{
    private TokenService $tokenService;
    private OidcTokenService $oidcTokenService;
    private UserRepository $userRepository;
    private LoggerInterface $logger;
    private CookieTokenAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->tokenService = $this->createMock(TokenService::class);
        $this->oidcTokenService = $this->createMock(OidcTokenService::class);
        $this->userRepository = $this->createMock(UserRepository::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->authenticator = new CookieTokenAuthenticator(
            $this->tokenService,
            $this->oidcTokenService,
            $this->userRepository,
            $this->logger
        );
    }

    // ========== supports() Tests ==========

    public function testSupportsReturnsTrueWithAppTokenCookie(): void
    {
        $request = new Request();
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'app-token');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithOidcTokenCookie(): void
    {
        $request = new Request();
        $request->cookies->set(OidcTokenService::OIDC_ACCESS_COOKIE, 'oidc-token');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsTrueWithAuthorizationHeader(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer header-token');

        $this->assertTrue($this->authenticator->supports($request));
    }

    public function testSupportsReturnsFalseWithoutToken(): void
    {
        $request = new Request();

        $this->assertFalse($this->authenticator->supports($request));
    }

    // ========== authenticate() - OIDC Token Tests ==========

    public function testAuthenticateSucceedsWithValidOidcToken(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $request = new Request();
        $request->cookies->set(OidcTokenService::OIDC_ACCESS_COOKIE, 'valid-oidc-token');
        $request->cookies->set(OidcTokenService::OIDC_PROVIDER_COOKIE, 'keycloak');

        $this->oidcTokenService
            ->expects($this->once())
            ->method('getUserFromOidcToken')
            ->with('valid-oidc-token', 'keycloak')
            ->willReturn($user);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('OIDC token authenticated successfully', $this->isType('array'));

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Passport::class, $passport);
    }

    public function testAuthenticateFailsWithExpiredOidcToken(): void
    {
        $request = new Request();
        $request->cookies->set(OidcTokenService::OIDC_ACCESS_COOKIE, 'expired-oidc-token');
        $request->cookies->set(OidcTokenService::OIDC_PROVIDER_COOKIE, 'keycloak');
        // No app token cookie set -> should throw after OIDC fallback fails

        $this->oidcTokenService
            ->expects($this->once())
            ->method('getUserFromOidcToken')
            ->with('expired-oidc-token', 'keycloak')
            ->willReturn(null);

        $this->logger
            ->expects($this->once())
            ->method('debug')
            ->with('OIDC token validation failed, falling back to app token');

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('OIDC token expired');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateUsesDefaultProviderWhenCookieMissing(): void
    {
        $request = new Request();
        $request->cookies->set(OidcTokenService::OIDC_ACCESS_COOKIE, 'oidc-token');
        // No OIDC_PROVIDER_COOKIE set

        $this->oidcTokenService
            ->expects($this->once())
            ->method('getUserFromOidcToken')
            ->with('oidc-token', 'keycloak') // Default provider
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);

        $this->authenticator->authenticate($request);
    }

    // ========== authenticate() - App Token Tests ==========

    public function testAuthenticateSucceedsWithValidAppToken(): void
    {
        $request = new Request();
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'valid-app-token');

        $this->tokenService
            ->expects($this->once())
            ->method('validateAccessToken')
            ->with('valid-app-token')
            ->willReturn(['user_id' => 456]);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Passport::class, $passport);

        // Verify UserBadge was created with correct user ID
        $badge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class, $badge);
        $this->assertSame('456', $badge->getUserIdentifier());
    }

    public function testAuthenticateFailsWithInvalidAppToken(): void
    {
        $request = new Request();
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'invalid-app-token');

        $this->tokenService
            ->expects($this->once())
            ->method('validateAccessToken')
            ->with('invalid-app-token')
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid or expired access token');

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateFailsWhenUserNotFound(): void
    {
        $user = $this->createMock(User::class);

        $request = new Request();
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'valid-token');

        $this->tokenService
            ->method('validateAccessToken')
            ->willReturn(['user_id' => 999]);

        $this->userRepository
            ->expects($this->once())
            ->method('find')
            ->with(999)
            ->willReturn(null);

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('User not found');

        $passport = $this->authenticator->authenticate($request);

        // Trigger UserBadge loader to test user not found scenario
        $badge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
        $badge->getUser();
    }

    public function testAuthenticateFailsWithMissingUserIdInPayload(): void
    {
        $request = new Request();
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'token-without-user-id');

        $this->tokenService
            ->method('validateAccessToken')
            ->willReturn(['some_key' => 'value']); // Valid payload structure but no user_id

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('Invalid token payload');

        $this->authenticator->authenticate($request);
    }

    // ========== authenticate() - Authorization Header Tests ==========

    public function testAuthenticateSucceedsWithAuthorizationHeader(): void
    {
        $request = new Request();
        $request->headers->set('Authorization', 'Bearer header-token-123');

        $this->tokenService
            ->expects($this->once())
            ->method('validateAccessToken')
            ->with('header-token-123')
            ->willReturn(['user_id' => 789]);

        $passport = $this->authenticator->authenticate($request);

        $this->assertInstanceOf(\Symfony\Component\Security\Http\Authenticator\Passport\Passport::class, $passport);

        // Verify UserBadge was created
        $badge = $passport->getBadge(\Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge::class);
        $this->assertSame('789', $badge->getUserIdentifier());
    }

    // ========== Token Extraction Priority Tests ==========

    public function testExtractTokenPrefersOidcOverAppToken(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $request = new Request();
        $request->cookies->set(OidcTokenService::OIDC_ACCESS_COOKIE, 'oidc-token');
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'app-token');

        // Should use OIDC token, not app token
        $this->oidcTokenService
            ->expects($this->once())
            ->method('getUserFromOidcToken')
            ->with('oidc-token', 'keycloak')
            ->willReturn($user);

        $this->tokenService
            ->expects($this->never())
            ->method('validateAccessToken');

        $this->authenticator->authenticate($request);
    }

    public function testExtractTokenPrefersAppTokenOverHeader(): void
    {
        $request = new Request();
        $request->cookies->set(TokenService::ACCESS_COOKIE, 'cookie-token');
        $request->headers->set('Authorization', 'Bearer header-token');

        // Should use cookie token, not header
        $this->tokenService
            ->expects($this->once())
            ->method('validateAccessToken')
            ->with('cookie-token')
            ->willReturn(['user_id' => 456]);

        $this->authenticator->authenticate($request);
    }

    public function testAuthenticateThrowsExceptionWhenNoTokenProvided(): void
    {
        $request = new Request();

        $this->expectException(AuthenticationException::class);
        $this->expectExceptionMessage('No access token provided');

        $this->authenticator->authenticate($request);
    }

    // ========== onAuthenticationSuccess() Test ==========

    public function testOnAuthenticationSuccessReturnsNull(): void
    {
        $request = new Request();
        $token = $this->createMock(\Symfony\Component\Security\Core\Authentication\Token\TokenInterface::class);

        $result = $this->authenticator->onAuthenticationSuccess($request, $token, 'api');

        $this->assertNull($result);
    }

    // ========== onAuthenticationFailure() Test ==========

    public function testOnAuthenticationFailureReturns401(): void
    {
        $request = new Request();
        $exception = new AuthenticationException('Test error');

        $response = $this->authenticator->onAuthenticationFailure($request, $exception);

        $this->assertInstanceOf(\Symfony\Component\HttpFoundation\JsonResponse::class, $response);
        $this->assertSame(401, $response->getStatusCode());

        $content = json_decode($response->getContent(), true);
        $this->assertSame('Authentication failed', $content['error']);
        $this->assertSame('Test error', $content['message']);
        $this->assertSame('AUTH_FAILED', $content['code']);
    }
}
