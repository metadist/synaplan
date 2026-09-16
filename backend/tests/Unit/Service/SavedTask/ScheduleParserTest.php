<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Service\SavedTask\Schedule\ScheduleParser;
use PHPUnit\Framework\TestCase;

final class ScheduleParserTest extends TestCase
{
    private ScheduleParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ScheduleParser();
    }

    public function testIntervalRejectsBelowFifteenMinutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->parser->nextRunAt(
            ['kind' => 'interval', 'every_minutes' => 5, 'tz' => 'UTC'],
            new \DateTimeImmutable('2026-03-29 10:00:00', new \DateTimeZone('UTC')),
        );
    }

    public function testDailyEuropeBerlinSpringForward(): void
    {
        // 2026-03-29 02:00 Europe/Berlin does not exist (clocks jump 02:00 → 03:00).
        $now = new \DateTimeImmutable('2026-03-28 23:00:00', new \DateTimeZone('UTC'));
        $next = $this->parser->nextRunAt(
            ['kind' => 'daily', 'at' => '07:00', 'tz' => 'Europe/Berlin'],
            $now,
        );

        $local = $next->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $this->assertSame('2026-03-29', $local->format('Y-m-d'));
        $this->assertSame('07:00', $local->format('H:i'));
    }

    public function testWeeklyEuropeBerlinFallBack(): void
    {
        // 2026-10-25 Europe/Berlin repeats 02:00. A 07:00 weekday slot is unambiguous.
        $now = new \DateTimeImmutable('2026-10-23 12:00:00', new \DateTimeZone('UTC'));
        $next = $this->parser->nextRunAt(
            ['kind' => 'weekly', 'days' => [1, 2, 3, 4, 5], 'at' => '07:00', 'tz' => 'Europe/Berlin'],
            $now,
        );

        $local = $next->setTimezone(new \DateTimeZone('Europe/Berlin'));
        $this->assertSame('2026-10-26', $local->format('Y-m-d'));
        $this->assertSame('1', $local->format('N'));
        $this->assertSame('07:00', $local->format('H:i'));
    }
}
