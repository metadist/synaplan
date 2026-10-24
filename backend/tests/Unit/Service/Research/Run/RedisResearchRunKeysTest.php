<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Infrastructure\RedisService;
use App\Service\Research\Run\RedisResearchRunKeys;
use App\Service\Research\Run\ResearchStoreUnavailable;
use PHPUnit\Framework\TestCase;

final class RedisResearchRunKeysTest extends TestCase
{
    public function testALostLeaseDoesNotCountAsAWrite(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects($this->once())->method('eval')->willReturn(0);

        self::assertFalse((new RedisResearchRunKeys($redis))->compareAndSet('claim', 'old', 'state', '{}', 10));
    }

    public function testRedisSilenceIsNotAHeldClaim(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('setIfAbsentMillis')->willReturn(null);

        $this->expectException(ResearchStoreUnavailable::class);
        $this->expectExceptionMessage('Start it again in a moment');
        (new RedisResearchRunKeys($redis))->setIfAbsent('claim', 'token', 1000);
    }
}
