<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\Token\RealtimeTokenService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

final class RealtimeTokenServiceTest extends TestCase
{
    private const SECRET = 'a-very-secret-key-of-sufficient-length-for-hs256';

    public function testIssuesConnectionTokenWithExpectedClaims(): void
    {
        $service = $this->buildService();
        $token = $service->issueConnectionToken('user:42', ['kind' => 'operator']);

        $decoded = (array) JWT::decode($token, new Key(self::SECRET, 'HS256'));

        $this->assertSame('user:42', $decoded['sub']);
        $this->assertSame('connection', $decoded['kind']);
        $this->assertSame(['kind' => 'operator'], (array) $decoded['info']);
        $this->assertSame(60, $decoded['exp'] - $decoded['iat']);
    }

    public function testIssuesSubscriptionTokenWithChannelClaim(): void
    {
        $service = $this->buildService();
        $token = $service->issueSubscriptionToken('user:42', 'widget:operators.wdg_x');

        $decoded = (array) JWT::decode($token, new Key(self::SECRET, 'HS256'));

        $this->assertSame('user:42', $decoded['sub']);
        $this->assertSame('widget:operators.wdg_x', $decoded['channel']);
        $this->assertSame('subscription', $decoded['kind']);
    }

    public function testRefusesToMintWithEmptySecret(): void
    {
        $service = new RealtimeTokenService(
            hmacSecret: '',
            clock: $this->fixedClock(),
            ttlSeconds: 60,
        );

        $this->expectException(\LogicException::class);
        $service->issueConnectionToken('user:42');
    }

    public function testTokenExpiresWithConfiguredTtl(): void
    {
        $service = new RealtimeTokenService(
            hmacSecret: self::SECRET,
            clock: $this->fixedClock(),
            ttlSeconds: 5,
        );

        $token = $service->issueConnectionToken('user:42');
        $decoded = (array) JWT::decode($token, new Key(self::SECRET, 'HS256'));

        $this->assertSame(5, $decoded['exp'] - $decoded['iat']);
        $this->assertSame(5, $service->ttlSeconds());
    }

    private function buildService(): RealtimeTokenService
    {
        return new RealtimeTokenService(
            hmacSecret: self::SECRET,
            clock: $this->fixedClock(),
            ttlSeconds: 60,
        );
    }

    private function fixedClock(): ClockInterface
    {
        // Use "now" so JWT signature verification (which uses the real wall
        // clock) does not flag tokens as expired during the test run.
        return new class implements ClockInterface {
            private readonly \DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new \DateTimeImmutable();
            }

            public function now(): \DateTimeImmutable
            {
                return $this->now;
            }
        };
    }
}
