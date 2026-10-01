<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Scheduler;

use App\Service\Scheduler\SchedulerSlotPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchedulerSlotPolicyTest extends TestCase
{
    private SchedulerSlotPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new SchedulerSlotPolicy();
    }

    /**
     * @return iterable<string, array{0: ?int, 1: int, 2: int, 3: bool, 4: int}>
     */
    public static function intervalCases(): iterable
    {
        yield 'never ran' => [null, 1_000_000, 3600, true, 0];
        yield 'elapsed equals interval' => [1_000_000 - 3600, 1_000_000, 3600, true, 0];
        yield 'elapsed exceeds interval' => [1_000_000 - 3601, 1_000_000, 3600, true, 0];
        yield 'one second short' => [1_000_000 - 3599, 1_000_000, 3600, false, 1];
        yield 'just claimed' => [1_000_000, 1_000_000, 3600, false, 3600];
        yield 'clock ahead of now' => [1_000_050, 1_000_000, 10, false, 60];
        yield 'claim more than a day ahead is repaired' => [1_000_000 + 86_401, 1_000_000, 3600, true, 0];
        yield 'weekly interval waits the rest of the week' => [1_000_000 - (6 * 86_400), 1_000_000, 7 * 86_400, false, 86_400];
        yield 'weekly interval is due after seven days' => [1_000_000 - (7 * 86_400), 1_000_000, 7 * 86_400, true, 0];
    }

    #[DataProvider('intervalCases')]
    public function testIntervalMode(?int $lastStart, int $now, int $interval, bool $due, int $seconds): void
    {
        $decision = $this->policy->evaluateInterval($lastStart, $now, $interval);

        self::assertSame($due, $decision->due);
        self::assertSame($seconds, $decision->secondsUntilDue);
    }

    /**
     * @return iterable<string, array{0: ?string, 1: string, 2: int, 3: int, 4: bool, 5: ?string}>
     */
    public static function atCases(): iterable
    {
        yield 'never ran before today slot' => [null, '2026-10-01 01:00:00', 3, 30, true, null];
        yield 'never ran exactly on the slot' => [null, '2026-10-01 03:30:00', 3, 30, true, null];
        yield 'never ran after today slot' => [null, '2026-10-01 18:00:00', 3, 30, true, null];
        yield 'first claim shortly before the slot skips that slot' => ['2026-10-01 01:00:00', '2026-10-01 01:05:00', 3, 30, false, '2026-10-02 03:30:00'];
        yield 'first claim one minute before the slot does not run again at the slot' => ['2026-10-01 03:29:00', '2026-10-01 03:30:00', 3, 30, false, '2026-10-02 03:30:00'];
        yield 'first claim in the evening skips the next morning' => ['2026-10-01 16:00:00', '2026-10-02 03:30:00', 3, 30, false, '2026-10-03 03:30:00'];
        yield 'first claim twelve hours before the slot runs at the slot' => ['2026-10-01 15:30:00', '2026-10-02 03:30:00', 3, 30, true, null];
        yield 'claim far in the future is repaired' => ['2026-10-05 03:30:00', '2026-10-01 18:00:00', 3, 30, true, null];
        yield 'claim from a node two minutes ahead holds' => ['2026-10-01 03:32:00', '2026-10-01 03:30:00', 3, 30, false, '2026-10-02 03:30:00'];
        yield 'claimed exactly on the slot waits a day' => ['2026-10-01 03:30:00', '2026-10-01 03:30:00', 3, 30, false, '2026-10-02 03:30:00'];
        yield 'claimed on the slot one second later' => ['2026-10-01 03:30:00', '2026-10-01 03:30:01', 3, 30, false, '2026-10-02 03:30:00'];
        yield 'one second before slot previous claim holds' => ['2026-09-30 03:30:00', '2026-10-01 03:29:59', 3, 30, false, '2026-10-01 03:30:00'];
        yield 'missed the previous slot' => ['2026-09-30 03:29:59', '2026-10-01 03:29:59', 3, 30, true, null];
        yield 'claimed earlier today after the slot' => ['2026-10-01 04:00:00', '2026-10-01 18:00:00', 3, 30, false, '2026-10-02 03:30:00'];
        yield 'one second before midnight' => ['2026-10-01 00:00:00', '2026-10-01 23:59:59', 0, 0, false, '2026-10-02 00:00:00'];
        yield 'exactly midnight already ran' => ['2026-10-02 00:00:00', '2026-10-02 00:00:00', 0, 0, false, '2026-10-03 00:00:00'];
        yield 'exactly midnight previous day is due' => ['2026-10-01 00:00:00', '2026-10-02 00:00:00', 0, 0, true, null];
        yield 'month boundary waits for the same clock time' => ['2026-02-28 23:45:00', '2026-03-01 00:30:00', 23, 45, false, '2026-03-01 23:45:00'];
        yield 'leap day waits until the next midnight' => ['2024-02-29 00:00:00', '2024-02-29 12:00:00', 0, 0, false, '2024-03-01 00:00:00'];
        yield 'day after leap day is due' => ['2024-02-29 00:00:00', '2024-03-01 00:00:00', 0, 0, true, null];
    }

    #[DataProvider('atCases')]
    public function testAtMode(?string $lastStart, string $now, int $hour, int $minute, bool $due, ?string $next): void
    {
        $decision = $this->policy->evaluateAt(
            null === $lastStart ? null : self::utc($lastStart),
            self::utc($now),
            $hour,
            $minute,
        );

        self::assertSame($due, $decision->due);
        if ($due) {
            self::assertSame(0, $decision->secondsUntilDue);

            return;
        }

        self::assertNotNull($next);
        self::assertSame(self::utc($next) - self::utc($now), $decision->secondsUntilDue);
        self::assertGreaterThanOrEqual(1, $decision->secondsUntilDue);
    }

    public function testIntervalRejectsNonPositive(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->evaluateInterval(null, 1_000_000, 0);
    }

    public function testAtRejectsAnImpossibleClock(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->policy->evaluateAt(null, 1_000_000, 24, 0);
    }

    public function testRetryDelayIsOneMinute(): void
    {
        self::assertSame(60, SchedulerSlotPolicy::RETRY_DELAY_SECONDS);
    }

    private static function utc(string $when): int
    {
        return (new \DateTimeImmutable($when, new \DateTimeZone('UTC')))->getTimestamp();
    }
}
