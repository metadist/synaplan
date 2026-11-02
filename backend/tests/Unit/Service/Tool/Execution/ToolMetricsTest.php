<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool\Execution;

use App\Service\Infrastructure\RedisService;
use App\Service\Tool\Execution\ToolMetrics;
use PHPUnit\Framework\TestCase;

final class ToolMetricsTest extends TestCase
{
    public function testACallIsCountedInThisProcessAndInRedis(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::once())->method('increment')->with('tool:calls:mcp:read:done');
        $metrics = new ToolMetrics($redis);

        $metrics->record('mcp', 'read', 'done');

        self::assertSame(1, $metrics->count('mcp', 'read', 'done'));
        self::assertStringContainsString('synaplan_tool_calls_total{source="mcp",kind="read",outcome="done"} 1', $metrics->prometheus());
    }

    public function testALabelOutsideTheAllowlistDoesNotReachTheKey(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::once())->method('increment')->with('tool:calls:other:read:done');
        $metrics = new ToolMetrics($redis);

        $metrics->record('user-7', 'read', 'done');

        self::assertSame(1, $metrics->count('other', 'read', 'done'));
    }
}
