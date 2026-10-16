<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Scheduler;

use App\Service\Infrastructure\RedisService;
use App\Service\Scheduler\ScheduledJobs;
use App\Service\Scheduler\ScheduledJobStatusReader;
use App\Service\Scheduler\ScheduledJobStatusStore;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class ScheduledJobStatusReaderTest extends TestCase
{
    private const NOW = 1_700_000_000;

    public function testRunningWhenTheNewestTickFinishIsInsideMaxAge(): void
    {
        $report = $this->reader([
            'app:media:reap-jobs' => self::NOW - 5_000,
            'app:chat:reap-stuck' => self::NOW - 10,
        ])->read(600);

        self::assertSame(ScheduledJobStatusReader::STATE_RUNNING, $report->state);
        self::assertCount(count(ScheduledJobs::all()), $report->jobs);
        self::assertSame('app:media:reap-jobs', $report->jobs[0]->command);
        self::assertSame('tick', $report->jobs[0]->lane);
        self::assertSame('web-1', $report->jobs[1]->host);
        self::assertSame(0, $report->jobs[1]->lastExitCode);
    }

    public function testBoundaryAgeStillCountsAsRunning(): void
    {
        $report = $this->reader([
            'app:process-emails' => self::NOW - 600,
        ])->read(600);

        self::assertSame(ScheduledJobStatusReader::STATE_RUNNING, $report->state);
    }

    public function testStaleWhenTheNewestTickFinishIsOlderThanMaxAge(): void
    {
        $report = $this->reader([
            'app:media:reap-jobs' => self::NOW - 601,
            'app:desktop:reap-jobs' => self::NOW - 5_000,
        ])->read(600);

        self::assertSame(ScheduledJobStatusReader::STATE_STALE, $report->state);
    }

    public function testNeverWhenNoTickJobHasFinished(): void
    {
        $report = $this->reader([])->read(600);

        self::assertSame(ScheduledJobStatusReader::STATE_NEVER, $report->state);
        self::assertNull($report->jobs[0]->lastFinishedAt);
    }

    public function testAFreshHourlyJobDoesNotMakeTheTickLaneLookAlive(): void
    {
        $report = $this->reader([
            'app:files:reap-ephemeral' => self::NOW,
        ])->read(600);

        self::assertSame(ScheduledJobStatusReader::STATE_NEVER, $report->state);
    }

    public function testCorruptJsonIsTreatedAsNeverRecorded(): void
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('get')->willReturn('not-json');
        $reader = new ScheduledJobStatusReader(
            new ScheduledJobStatusStore($redis),
            new MockClock(new \DateTimeImmutable('@'.self::NOW)),
        );

        $report = $reader->read(600);

        self::assertSame(ScheduledJobStatusReader::STATE_NEVER, $report->state);
        self::assertNull($report->jobs[0]->lastStartedAt);
    }

    public function testRedisDownIsAnErrorBeforeAnyRead(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->method('isAvailable')->willReturn(false);
        $redis->expects(self::never())->method('get');
        $reader = new ScheduledJobStatusReader(
            new ScheduledJobStatusStore($redis),
            new MockClock(new \DateTimeImmutable('@'.self::NOW)),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Redis is unavailable');
        $reader->read(600);
    }

    public function testMaxAgeMustBePositive(): void
    {
        $redis = $this->createMock(RedisService::class);
        $redis->expects(self::never())->method('isAvailable');
        $reader = new ScheduledJobStatusReader(new ScheduledJobStatusStore($redis));

        $this->expectException(\InvalidArgumentException::class);
        $reader->read(0);
    }

    /**
     * @param array<string, int> $finishedAtByCommand
     */
    private function reader(array $finishedAtByCommand): ScheduledJobStatusReader
    {
        $redis = $this->createStub(RedisService::class);
        $redis->method('isAvailable')->willReturn(true);
        $redis->method('get')->willReturnCallback(function (string $key) use ($finishedAtByCommand): ?string {
            foreach ($finishedAtByCommand as $command => $finishedAt) {
                if (ScheduledJobStatusStore::key($command) !== $key) {
                    continue;
                }

                return json_encode([
                    'lastStartedAt' => $finishedAt - 1,
                    'lastFinishedAt' => $finishedAt,
                    'lastExitCode' => 0,
                    'host' => 'web-1',
                ], \JSON_THROW_ON_ERROR);
            }

            return null;
        });

        return new ScheduledJobStatusReader(
            new ScheduledJobStatusStore($redis),
            new MockClock(new \DateTimeImmutable('@'.self::NOW)),
        );
    }
}
