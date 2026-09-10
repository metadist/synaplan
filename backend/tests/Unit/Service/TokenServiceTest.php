<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Entity\Token;
use App\Entity\User;
use App\Repository\TokenRepository;
use App\Repository\UserRepository;
use App\Service\Auth\AuthCookieFactory;
use App\Service\TokenService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for {@see TokenService}.
 *
 * Login sessions are HMAC-signed access cookies plus a DB-backed refresh
 * token. A process restart must not invalidate either: the access cookie
 * is signed with the injected APP_SECRET (not $_ENV), and the refresh
 * row lives in MariaDB with a 30-day sliding expiry.
 */
final class TokenServiceTest extends TestCase
{
    private const SECRET_A = 'unit-test-secret-a';
    private const SECRET_B = 'unit-test-secret-b';

    public function testRefreshTokenTtlIsThirtyDays(): void
    {
        self::assertSame(30 * 86400, TokenService::REFRESH_TOKEN_TTL);
    }

    public function testAccessTokenRoundTripUsesInjectedSecret(): void
    {
        $service = $this->makeService(self::SECRET_A);
        $token = $service->generateAccessToken($this->makeUser());

        $payload = $service->validateAccessToken($token);

        self::assertNotNull($payload);
        self::assertSame(7, $payload['user_id']);
        self::assertSame(TokenService::TYPE_ACCESS, $payload['type']);
    }

    public function testAccessTokenSignedWithADifferentSecretIsRejected(): void
    {
        $minted = $this->makeService(self::SECRET_A)->generateAccessToken($this->makeUser());

        self::assertNull($this->makeService(self::SECRET_B)->validateAccessToken($minted));
    }

    public function testRefreshTokensSlidesExpiryForward(): void
    {
        $user = $this->makeUser();
        $refresh = new Token();
        $refresh->setUser($user);
        $refresh->setType(TokenService::TYPE_REFRESH);
        $refresh->setToken('refresh-abc');
        $refresh->setExpires(time() + 3600);
        $refresh->setUsed(false);

        $tokenRepository = $this->createMock(TokenRepository::class);
        $tokenRepository->method('findValidToken')->willReturn($refresh);
        $tokenRepository->expects(self::once())
            ->method('save')
            ->with(self::callback(static function (Token $token): bool {
                $remaining = $token->getExpires() - time();

                return $remaining >= TokenService::REFRESH_TOKEN_TTL - 2
                    && $remaining <= TokenService::REFRESH_TOKEN_TTL;
            }));

        $service = $this->makeService(self::SECRET_A, $tokenRepository);
        $result = $service->refreshTokens('refresh-abc');

        self::assertNotNull($result);
        self::assertArrayHasKey('access_token', $result);
        self::assertSame($user, $result['user']);
        self::assertSame('refresh-abc', $result['refresh_token']);
        self::assertNotNull($service->validateAccessToken($result['access_token']));
    }

    public function testRefreshCookieLifetimeMatchesRefreshTtl(): void
    {
        $before = time();
        $cookie = $this->makeService(self::SECRET_A)->createRefreshCookie('refresh-abc');
        $after = time();

        self::assertSame(TokenService::REFRESH_COOKIE, $cookie->getName());
        self::assertGreaterThanOrEqual($before + TokenService::REFRESH_TOKEN_TTL, $cookie->getExpiresTime());
        self::assertLessThanOrEqual($after + TokenService::REFRESH_TOKEN_TTL, $cookie->getExpiresTime());
    }

    private function makeService(string $secret, ?TokenRepository $tokenRepository = null): TokenService
    {
        return new TokenService(
            $tokenRepository ?? $this->createMock(TokenRepository::class),
            $this->createMock(UserRepository::class),
            new NullLogger(),
            new AuthCookieFactory('test', 'https://synaplan.example.com'),
            $secret,
        );
    }

    private function makeUser(): User&MockObject
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(7);
        $user->method('getMail')->willReturn('ada@example.com');
        $user->method('getRoles')->willReturn(['ROLE_USER']);
        $user->method('getUserLevel')->willReturn('PRO');

        return $user;
    }
}
