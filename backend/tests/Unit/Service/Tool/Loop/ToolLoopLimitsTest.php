<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Loop;

use App\Service\Tool\Execution\ToolLimits;
use App\Service\Tool\Loop\ToolLoopLimits;
use App\Service\Tool\Loop\ToolLoopStop;
use App\Service\Tool\Loop\ToolLoopTurn;
use PHPUnit\Framework\TestCase;

final class ToolLoopLimitsTest extends TestCase
{
    public function testDefaultsAreTheToolLimitsNumbers(): void
    {
        $limits = ToolLoopLimits::defaults();

        self::assertSame(ToolLimits::MAX_ITERATIONS_DEFAULT, $limits->maxIterations);
        self::assertSame(ToolLimits::DEADLINE_SECONDS, $limits->deadlineSeconds);
        self::assertSame(ToolLimits::MAX_TOOLS_PER_TURN, $limits->maxToolsPerTurn);
    }

    public function testDefaultsTakeTheConfiguredIterationCap(): void
    {
        self::assertSame(3, ToolLoopLimits::defaults(3)->maxIterations);
        self::assertSame(ToolLimits::DEADLINE_SECONDS, ToolLoopLimits::defaults(3)->deadlineSeconds);
    }

    public function testUnboundedHasNoCapAndNoDeadline(): void
    {
        $limits = ToolLoopLimits::unbounded();

        self::assertNull($limits->maxIterations);
        self::assertNull($limits->deadlineSeconds);
        self::assertSame(ToolLimits::MAX_TOOLS_PER_TURN, $limits->maxToolsPerTurn);
    }

    public function testRejectsFewerThanOneToolPerTurn(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolLoopLimits(4, 240, 0);
    }

    public function testRejectsANegativeIterationCap(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolLoopLimits(-1, 240);
    }

    public function testRejectsANegativeDeadline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ToolLoopLimits(4, -1);
    }

    public function testRejectsARunTurnWithoutCalls(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolLoopTurn::run([]);
    }

    public function testTheLoopAloneReportsCapAndDeadline(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ToolLoopTurn::stop(ToolLoopStop::Deadline);
    }
}
