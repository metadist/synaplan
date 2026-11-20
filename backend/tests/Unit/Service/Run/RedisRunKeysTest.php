<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Infrastructure\RedisService;
use App\Service\Run\RedisRunKeys;
use App\Service\Run\RunStoreUnavailable;
use PHPUnit\Framework\TestCase;

final class RedisRunKeysTest extends TestCase
{
    public function testALostLeaseDoesNotCountAsAWrite(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects($this->once())->method('eval')->willReturn(0);

        self::assertFalse((new RedisRunKeys($redis))->compareAndSet('claim', 'old', 'state', '{}', 10));
    }

    public function testAnOpenRunIsWrittenWithoutExpiry(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects($this->once())->method('eval')
            ->with(self::anything(), ['claim', 'state'], ['token', '{}', '0'])
            ->willReturn(1);

        self::assertTrue((new RedisRunKeys($redis))->compareAndSet('claim', 'token', 'state', '{}', null));
    }

    public function testRedisSilenceIsNotAHeldClaim(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(null);

        $this->expectException(RunStoreUnavailable::class);
        (new RedisRunKeys($redis))->claim('claim', 'token', 1000, 'claims', 1000, 'member');
    }

    public function testRedisSilenceIsNotAMissingRun(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(null);

        $this->expectException(RunStoreUnavailable::class);
        (new RedisRunKeys($redis))->get('run:state:x');
    }

    public function testRedisSilenceIsNotAnEmptySet(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(null);

        $this->expectException(RunStoreUnavailable::class);
        (new RedisRunKeys($redis))->members('run:active');
    }

    public function testRedisSilenceIsNotAHeldKey(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(null);

        $this->expectException(RunStoreUnavailable::class);
        (new RedisRunKeys($redis))->setIfAbsent('run:delivered:x', '1', 60);
    }

    public function testAHeldKeyIsNotTakenAgain(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects($this->once())->method('eval')
            ->with(self::anything(), ['run:delivered:x'], ['1', '60'])
            ->willReturn(0);

        self::assertFalse((new RedisRunKeys($redis))->setIfAbsent('run:delivered:x', '1', 60));
    }

    public function testADeletionRedisDidNotConfirmIsNotReportedAsDone(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('delete')->willReturn(false);

        $this->expectException(RunStoreUnavailable::class);
        (new RedisRunKeys($redis))->delete('run:state:x');
    }

    public function testAnEventForAGoneRunIsNotStored(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('eval')->willReturn(0);

        self::assertNull((new RedisRunKeys($redis))->appendEvent('seq', 'list', 'run', '{}'));
    }

    public function testAnEventWakesAReaderBlockedOnItsRun(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::once())->method('eval')
            ->with(self::stringContains("'XADD', KEYS[4]"), ['seq', 'list', 'run', 'list:wake'], ['{}'])
            ->willReturn(6);
        $redis->expects(self::once())->method('readStream')->with('list:wake', '0-5', 1000)->willReturn([]);
        $keys = new RedisRunKeys($redis);

        self::assertSame(6, $keys->appendEvent('seq', 'list', 'run', '{}'));
        $keys->awaitEvent('list', 5, 1000);
    }
}
