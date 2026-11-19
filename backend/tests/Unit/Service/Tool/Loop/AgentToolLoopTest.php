<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Loop;

use App\Service\Tool\Execution\ToolLimits;
use App\Service\Tool\Loop\AgentToolLoop;
use App\Service\Tool\Loop\ToolLoopLimits;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class AgentToolLoopTest extends TestCase
{
    public function testWithoutCapOrDeadlineItStopsOnlyWhenTheModelAnswersWithoutATool(): void
    {
        $clock = new MockClock();
        $toolTurns = 3 * ToolLimits::MAX_ITERATIONS_DEFAULT;
        $adapter = new ScriptedToolLoopAdapter(
            static fn (int $i): ToolLoopTurn => $i < $toolTurns ? ToolLoopTurn::run([['id' => 'c'.$i]]) : ToolLoopTurn::stop(ToolLoopStop::Answered),
            $clock,
            secondsPerTurn: ToolLimits::DEADLINE_SECONDS,
        );

        $run = (new AgentToolLoop($clock))->run(ToolLoopLimits::unbounded(), $adapter);

        self::assertSame(ToolLoopStop::Answered, $run->stop);
        self::assertSame($toolTurns + 1, $run->iterations);
        self::assertCount($toolTurns, $adapter->executed);
    }

    public function testStopsAtTheIterationCap(): void
    {
        $adapter = new ScriptedToolLoopAdapter(static fn (): ToolLoopTurn => ToolLoopTurn::run([['id' => 'c']]));

        $run = (new AgentToolLoop(new MockClock()))->run(new ToolLoopLimits(3, null), $adapter);

        self::assertSame(ToolLoopStop::Iterations, $run->stop);
        self::assertSame(3, $run->iterations);
        self::assertSame([0, 1, 2], $adapter->turns);
    }

    public function testAZeroCapNeverCallsTheModel(): void
    {
        $adapter = new ScriptedToolLoopAdapter(static fn (): ToolLoopTurn => ToolLoopTurn::stop(ToolLoopStop::Answered));

        $run = (new AgentToolLoop(new MockClock()))->run(new ToolLoopLimits(0, null), $adapter);

        self::assertSame(ToolLoopStop::Iterations, $run->stop);
        self::assertSame(0, $run->iterations);
        self::assertSame([], $adapter->turns);
    }

    public function testStopsBeforeTheNextTurnOnceTheDeadlinePassed(): void
    {
        $clock = new MockClock();
        $adapter = new ScriptedToolLoopAdapter(
            static fn (): ToolLoopTurn => ToolLoopTurn::run([['id' => 'c']]),
            $clock,
            secondsPerTurn: 100,
        );

        $run = (new AgentToolLoop($clock))->run(new ToolLoopLimits(null, 240), $adapter);

        self::assertSame(ToolLoopStop::Deadline, $run->stop);
        self::assertSame(3, $run->iterations);
    }

    public function testAtTheDeadlineExactlyItStillTakesTheTurn(): void
    {
        $clock = new MockClock();
        $adapter = new ScriptedToolLoopAdapter(
            static fn (int $i): ToolLoopTurn => 0 === $i ? ToolLoopTurn::run([['id' => 'c']]) : ToolLoopTurn::stop(ToolLoopStop::Answered),
            $clock,
            secondsPerTurn: 240,
        );

        $run = (new AgentToolLoop($clock))->run(new ToolLoopLimits(null, 240), $adapter);

        self::assertSame(ToolLoopStop::Answered, $run->stop);
        self::assertSame(2, $run->iterations);
    }

    public function testHandsTheAdapterAtMostTheToolsPerTurn(): void
    {
        $calls = array_map(static fn (int $n): array => ['id' => 'c'.$n], range(1, 20));
        $adapter = new ScriptedToolLoopAdapter(
            static fn (int $i): ToolLoopTurn => 0 === $i ? ToolLoopTurn::run($calls) : ToolLoopTurn::stop(ToolLoopStop::Answered),
        );

        (new AgentToolLoop(new MockClock()))->run(ToolLoopLimits::defaults(), $adapter);

        self::assertCount(ToolLimits::MAX_TOOLS_PER_TURN, $adapter->executed[0]);
        self::assertSame(['id' => 'c1'], $adapter->executed[0][0]);
        self::assertSame(['id' => 'c16'], $adapter->executed[0][15]);
    }

    public function testAnAgainTurnSpendsAnIterationAndRunsNoTool(): void
    {
        $adapter = new ScriptedToolLoopAdapter(
            static fn (int $i): ToolLoopTurn => 0 === $i ? ToolLoopTurn::again() : ToolLoopTurn::stop(ToolLoopStop::Answered),
        );

        $run = (new AgentToolLoop(new MockClock()))->run(new ToolLoopLimits(2, null), $adapter);

        self::assertSame(ToolLoopStop::Answered, $run->stop);
        self::assertSame(2, $run->iterations);
        self::assertSame([], $adapter->executed);
    }

    public function testTheAdapterEndsTheRunAfterExecuting(): void
    {
        $adapter = new ScriptedToolLoopAdapter(
            static fn (): ToolLoopTurn => ToolLoopTurn::run([['id' => 'c']]),
            stopAfterExecute: ToolLoopStop::ClientTools,
        );

        $run = (new AgentToolLoop(new MockClock()))->run(ToolLoopLimits::defaults(), $adapter);

        self::assertSame(ToolLoopStop::ClientTools, $run->stop);
        self::assertSame(1, $run->iterations);
    }

    public function testTheAdapterStopReasonPassesThrough(): void
    {
        $adapter = new ScriptedToolLoopAdapter(static fn (): ToolLoopTurn => ToolLoopTurn::stop(ToolLoopStop::Failed));

        $run = (new AgentToolLoop(new MockClock()))->run(ToolLoopLimits::defaults(), $adapter);

        self::assertSame(ToolLoopStop::Failed, $run->stop);
        self::assertSame(1, $run->iterations);
    }
}
