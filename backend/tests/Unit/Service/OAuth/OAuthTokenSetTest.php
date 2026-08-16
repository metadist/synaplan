<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\OAuth;

use App\Service\OAuth\OAuthException;
use App\Service\OAuth\OAuthTokenSet;
use PHPUnit\Framework\TestCase;

final class OAuthTokenSetTest extends TestCase
{
    public function testBuildsFromTokenResponse(): void
    {
        $tokens = OAuthTokenSet::fromTokenResponse([
            'access_token' => 'at-1',
            'refresh_token' => 'rt-1',
            'expires_in' => 3600,
            'scope' => 'Mail.Read offline_access',
        ], now: 1_000);

        self::assertSame('at-1', $tokens->accessToken);
        self::assertSame('rt-1', $tokens->refreshToken);
        self::assertSame(4_600, $tokens->expiresAt);
        self::assertSame(['Mail.Read', 'offline_access'], $tokens->scopes);
    }

    /**
     * Microsoft omits refresh_token on most refreshes. Dropping it would turn a
     * permanent connection into a one-hour connection.
     */
    public function testRefreshResponseWithoutRefreshTokenKeepsThePreviousOne(): void
    {
        $tokens = OAuthTokenSet::fromTokenResponse(
            ['access_token' => 'at-2', 'expires_in' => 60],
            fallbackRefreshToken: 'rt-original',
            now: 1_000,
        );

        self::assertSame('rt-original', $tokens->refreshToken);
    }

    public function testRejectsResponseWithoutAccessToken(): void
    {
        $this->expectException(OAuthException::class);

        OAuthTokenSet::fromTokenResponse(['expires_in' => 60]);
    }

    public function testJsonRoundTrip(): void
    {
        $original = new OAuthTokenSet('at', 'rt', 12_345, ['Mail.Read']);
        $restored = OAuthTokenSet::fromJson($original->toJson());

        self::assertEquals($original, $restored);
    }

    public function testRejectsUnreadableStoredJson(): void
    {
        $this->expectException(OAuthException::class);

        OAuthTokenSet::fromJson('not json');
    }

    public function testExpiryHonoursSkew(): void
    {
        $tokens = new OAuthTokenSet('at', 'rt', 1_000);

        self::assertFalse($tokens->isExpired(60, 900));
        self::assertTrue($tokens->isExpired(60, 950), 'inside the skew window counts as expired');
        self::assertTrue($tokens->isExpired(0, 1_000));
    }

    public function testUnknownExpiryCountsAsExpired(): void
    {
        self::assertTrue((new OAuthTokenSet('at', 'rt', 0))->isExpired(0, 1));
    }

    public function testMergeKeepsRefreshTokenAndScopesWhenTheRefreshOmitsThem(): void
    {
        $current = new OAuthTokenSet('old', 'rt-original', 100, ['Mail.Read']);
        $merged = $current->withTokens(new OAuthTokenSet('new', '', 900));

        self::assertSame('new', $merged->accessToken);
        self::assertSame('rt-original', $merged->refreshToken);
        self::assertSame(900, $merged->expiresAt);
        self::assertSame(['Mail.Read'], $merged->scopes);
    }
}
