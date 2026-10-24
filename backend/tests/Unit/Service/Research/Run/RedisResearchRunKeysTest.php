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
        $redis->method('eval')->willReturn(null);

        $this->expectException(ResearchStoreUnavailable::class);
        $this->expectExceptionMessage('Start it again in a moment');
        (new RedisResearchRunKeys($redis))->claim('claim', 'token', 1000, 'claims', 1000, 'member');
    }

    public function testRedisSilenceIsNotAMissingRun(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(null);

        $this->expectException(ResearchStoreUnavailable::class);
        (new RedisResearchRunKeys($redis))->get('research:run:rr_0123456789abcdef');
    }

    public function testRedisSilenceIsNotAnEmptySet(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(null);

        $this->expectException(ResearchStoreUnavailable::class);
        (new RedisResearchRunKeys($redis))->members('research:owner:1');
    }

    public function testADeletionRedisDidNotConfirmIsNotReportedAsDone(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('delete')->willReturn(false);

        $this->expectException(ResearchStoreUnavailable::class);
        (new RedisResearchRunKeys($redis))->delete('research:run:rr_0123456789abcdef');
    }

    public function testAnEventForAGoneRunIsNotStored(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(0);

        self::assertNull((new RedisResearchRunKeys($redis))->appendEvent('rr_0123456789abcdef', '{}'));
    }
}
