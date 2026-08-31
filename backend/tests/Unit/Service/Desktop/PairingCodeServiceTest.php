<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Service\Desktop\Exception\PairingLimitException;
use App\Service\Desktop\PairingCodeService;
use App\Service\Infrastructure\RedisService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class PairingCodeServiceTest extends TestCase
{
    private RedisService&MockObject $redis;
    private PairingCodeService $service;

    protected function setUp(): void
    {
        $this->redis = $this->createMock(RedisService::class);
        $this->service = new PairingCodeService($this->redis);
    }

    public function testCreateMintsCodeAndRegistersOutstanding(): void
    {
        $this->redis->method('sMembers')->willReturn([]);
        $this->redis->method('increment')->willReturn(1);
        // generateUniqueCode() checks the freshly-minted code does not collide.
        $this->redis->method('exists')->willReturn(false);

        $setKeys = [];
        $this->redis->method('set')->willReturnCallback(function (string $key) use (&$setKeys): bool {
            $setKeys[] = $key;

            return true;
        });
        $this->redis->expects(self::once())->method('sAdd');

        $result = $this->service->create(42);

        self::assertArrayHasKey('code', $result);
        self::assertArrayHasKey('expiresAt', $result);
        self::assertSame(8, \strlen($result['code']));
        self::assertMatchesRegularExpression('/^[2-9A-HJ-NP-Z]{8}$/', $result['code']);
        self::assertGreaterThan(time(), $result['expiresAt']);
        self::assertNotEmpty($setKeys);
    }

    public function testCreateThrowsWhenTooManyOutstanding(): void
    {
        // Five live outstanding codes → at the cap.
        $this->redis->method('sMembers')->willReturn(['A', 'B', 'C', 'D', 'E']);
        $this->redis->method('exists')->willReturn(true);

        $this->expectException(PairingLimitException::class);
        $this->service->create(1);
    }

    public function testCreateThrowsWhenHourlyLimitExceeded(): void
    {
        $this->redis->method('sMembers')->willReturn([]);
        // 21st creation in the hour → over the per-hour cap of 20.
        $this->redis->method('increment')->willReturn(21);

        $this->expectException(PairingLimitException::class);
        $this->service->create(1);
    }

    public function testConsumeReturnsUserIdAndDeletesCode(): void
    {
        $payload = json_encode(['userId' => 77, 'expiresAt' => time() + 300]);
        $this->redis->method('get')->willReturn($payload);
        $this->redis->expects(self::once())->method('delete');
        $this->redis->expects(self::once())->method('sRem');

        self::assertSame(77, $this->service->consume('AB3K7Q2M'));
    }

    public function testConsumeNormalizesLowercaseAndSeparators(): void
    {
        $payload = json_encode(['userId' => 5, 'expiresAt' => time() + 300]);
        $this->redis->expects(self::once())
            ->method('get')
            ->with(self::stringContains('AB3K7Q2M'))
            ->willReturn($payload);

        self::assertSame(5, $this->service->consume('ab3k-7q2m'));
    }

    public function testConsumeUnknownCodeReturnsNull(): void
    {
        $this->redis->method('get')->willReturn(null);

        self::assertNull($this->service->consume('ZZZZZZZZ'));
    }

    public function testConsumeExpiredCodeReturnsNull(): void
    {
        $payload = json_encode(['userId' => 5, 'expiresAt' => time() - 5]);
        $this->redis->method('get')->willReturn($payload);

        self::assertNull($this->service->consume('AB3K7Q2M'));
    }

    public function testConsumeEmptyCodeReturnsNullWithoutRedisHit(): void
    {
        $this->redis->expects(self::never())->method('get');

        self::assertNull($this->service->consume('   '));
    }

    public function testConsumeMalformedPayloadReturnsNull(): void
    {
        $this->redis->method('get')->willReturn('not-json');
        $this->redis->expects(self::once())->method('delete');

        self::assertNull($this->service->consume('AB3K7Q2M'));
    }
}
