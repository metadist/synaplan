<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\PlatformLink;

use App\Service\Infrastructure\RedisService;
use App\Service\PlatformLink\LinkCodeService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LinkCodeServiceTest extends TestCase
{
    private RedisService&MockObject $redis;
    private LinkCodeService $service;

    /** @var array<string, string> */
    private array $store = [];

    protected function setUp(): void
    {
        $this->store = [];
        $this->redis = $this->createMock(RedisService::class);
        $this->redis->method('increment')->willReturn(1);
        $this->redis->method('expire')->willReturn(true);
        $this->redis->method('set')->willReturnCallback(
            function (string $key, string $value): bool {
                $this->store[$key] = $value;

                return true;
            }
        );
        $this->redis->method('getAndDelete')->willReturnCallback(
            function (string $key): ?string {
                $value = $this->store[$key] ?? null;
                unset($this->store[$key]);

                return $value;
            }
        );
        $this->redis->expects(self::never())->method('get');
        $this->redis->expects(self::never())->method('delete');
        $this->service = new LinkCodeService($this->redis);
    }

    public function testSecondConsumeReturnsNull(): void
    {
        $created = $this->service->create([
            'userId' => 4,
            'instanceId' => 'pi_aaa',
            'externalId' => 'jdoe',
            'redirectUri' => 'https://files.example.org/cb',
        ]);

        $first = $this->service->consume($created['code']);
        $second = $this->service->consume($created['code']);

        self::assertNotNull($first);
        self::assertSame(4, $first['userId']);
        self::assertSame('pi_aaa', $first['instanceId']);
        self::assertNull($second);
    }

    public function testExpiredCodeReturnsNull(): void
    {
        $code = str_repeat('ab', 16);
        $this->store['platform_link:code:'.$code] = json_encode([
            'userId' => 4,
            'instanceId' => 'pi_aaa',
            'externalId' => 'jdoe',
            'redirectUri' => 'https://files.example.org/cb',
            'expiresAt' => time() - 10,
        ], \JSON_THROW_ON_ERROR);

        self::assertNull($this->service->consume($code));
        self::assertArrayNotHasKey('platform_link:code:'.$code, $this->store);
    }

    public function testCodeBoundToInstance(): void
    {
        $created = $this->service->create([
            'userId' => 4,
            'instanceId' => 'pi_one',
            'externalId' => 'jdoe',
            'redirectUri' => 'https://files.example.org/cb',
        ]);

        $consumed = $this->service->consume($created['code']);
        self::assertNotNull($consumed);
        self::assertSame('pi_one', $consumed['instanceId']);
        self::assertSame(32, \strlen($created['code']));
    }
}
