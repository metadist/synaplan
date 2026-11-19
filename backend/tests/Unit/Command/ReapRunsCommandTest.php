<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReapRunsCommand;
use App\Service\Chat\Run\StreamPumpConfig;
use App\Service\Chat\Run\StreamRedis;
use App\Service\Infrastructure\RedisService;
use App\Service\Run\RedisRunKeys;
use App\Service\Run\RunCheckpointStore;
use App\Service\Run\RunKeyspace;
use App\Service\Run\RunReaper;
use App\Tests\Unit\Service\Run\MemoryRunKeys;
use App\Tests\Unit\Service\Run\RecordingRunUnitBus;
use App\Tests\Unit\Service\Run\TestRunReapPolicy;
use App\Tests\Unit\Service\Run\TestRunState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ReapRunsCommandTest extends TestCase
{
    public function testOneFailingKernelReaperDoesNotStopTheOthers(): void
    {
        $silentRedis = $this->createStub(RedisService::class);
        $clock = new MockClock('2026-11-19 12:00:00');

        $command = new ReapRunsCommand(
            new StreamRedis($silentRedis, new StreamPumpConfig()),
            new NullLogger(),
            [
                $this->reaper(new RedisRunKeys($silentRedis), $clock),
                $this->reaper(new MemoryRunKeys($clock), $clock),
            ],
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('one reaper failed', $tester->getDisplay());
        self::assertStringContainsString('Kernel runs: 0 redispatched, 0 interrupted, 0 moved on after waiting.', $tester->getDisplay());
    }

    public function testWithoutKernelReapersTheChatReapStillSucceeds(): void
    {
        $command = new ReapRunsCommand(
            new StreamRedis($this->createStub(RedisService::class), new StreamPumpConfig()),
            new NullLogger(),
        );
        $tester = new CommandTester($command);

        self::assertSame(Command::SUCCESS, $tester->execute([]));
        self::assertStringContainsString('Failed 0 runs.', $tester->getDisplay());
    }

    private function reaper(MemoryRunKeys|RedisRunKeys $keys, MockClock $clock): RunReaper
    {
        $store = new RunCheckpointStore($keys, RunKeyspace::runs(), new TestRunState(), $clock);

        return new RunReaper($store, new TestRunReapPolicy(), new RecordingRunUnitBus(), $clock, new NullLogger(), 1000);
    }
}
