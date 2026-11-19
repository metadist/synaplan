<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Infrastructure\RedisService;
use App\Service\Research\Run\RedisResearchRunKeys;
use App\Service\Run\RedisRunKeys;
use App\Service\Run\RunStoreUnavailable;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * @return iterable<string, array{string}>
     */
    public static function sharedScripts(): iterable
    {
        yield 'claim' => ['CLAIM'];
        yield 'renew' => ['RENEW'];
        yield 'get' => ['GET'];
        yield 'members' => ['MEMBERS'];
        yield 'delete if equals' => ['DELETE_IF_EQUALS'];
    }

    /** The kernel's lease scripts are the research engine's, unchanged. */
    #[DataProvider('sharedScripts')]
    public function testTheLeaseScriptsMatchTheResearchEngine(string $name): void
    {
        self::assertSame(
            (new \ReflectionClassConstant(RedisResearchRunKeys::class, $name))->getValue(),
            (new \ReflectionClassConstant(RedisRunKeys::class, $name))->getValue(),
        );
    }
}
