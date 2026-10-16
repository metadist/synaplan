<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\SchedulerStatusCommand;
use App\Service\Scheduler\ScheduledJobStatus;
use App\Service\Scheduler\ScheduledJobStatusReader;
use App\Service\Scheduler\ScheduledJobStatusReport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SchedulerStatusCommandTest extends TestCase
{
    private const ERR = ['capture_stderr_separately' => true];

    public function testRunningExits0AndPrintsTheTable(): void
    {
        $reader = $this->createMock(ScheduledJobStatusReader::class);
        $reader->expects(self::once())->method('read')
            ->with(600)
            ->willReturn($this->report(ScheduledJobStatusReader::STATE_RUNNING));

        $tester = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(600, SchedulerStatusCommand::DEFAULT_MAX_AGE_SECONDS);
        self::assertSame(0, $tester->execute([], self::ERR));
        $display = $tester->getDisplay(true);
        self::assertStringStartsWith("running\n", $display);
        self::assertStringContainsString('app:media:reap-jobs', $display);
        self::assertStringContainsString('tick', $display);
        self::assertStringContainsString(gmdate('Y-m-d H:i:s', 1_700_000_000), $display);
        self::assertStringContainsString('web-1', $display);
        self::assertStringContainsString('Last start', $display);
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testJsonExitsWithTheStateAndOmitsTheTable(): void
    {
        $reader = $this->createStub(ScheduledJobStatusReader::class);
        $reader->method('read')->willReturn($this->report(ScheduledJobStatusReader::STATE_RUNNING));

        $tester = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(0, $tester->execute(['--json' => true], self::ERR));
        $decoded = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('running', $decoded['state']);
        self::assertSame('app:media:reap-jobs', $decoded['jobs'][0]['command']);
        self::assertSame('tick', $decoded['jobs'][0]['lane']);
        self::assertSame(1_700_000_000, $decoded['jobs'][0]['lastStartedAt']);
        self::assertSame(0, $decoded['jobs'][0]['lastExitCode']);
        self::assertNull($decoded['jobs'][1]['lastFinishedAt']);
        self::assertNull($decoded['jobs'][1]['host']);
        self::assertStringNotContainsString('Last start', $tester->getDisplay());
    }

    #[DataProvider('notRunning')]
    public function testStaleOrNeverExits2(string $state): void
    {
        $reader = $this->createStub(ScheduledJobStatusReader::class);
        $reader->method('read')->willReturn($this->report($state));

        $tester = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(2, $tester->execute([], self::ERR));
        self::assertStringStartsWith($state."\n", $tester->getDisplay(true));

        $json = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(2, $json->execute(['--json' => true], self::ERR));
        $decoded = json_decode($json->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame($state, $decoded['state']);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function notRunning(): iterable
    {
        yield 'stale' => [ScheduledJobStatusReader::STATE_STALE];
        yield 'never' => [ScheduledJobStatusReader::STATE_NEVER];
    }

    public function testMaxAgeIsForwarded(): void
    {
        $reader = $this->createMock(ScheduledJobStatusReader::class);
        $reader->expects(self::once())->method('read')
            ->with(90)
            ->willReturn($this->report(ScheduledJobStatusReader::STATE_RUNNING));

        $tester = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(0, $tester->execute(['--max-age' => '90'], self::ERR));
    }

    public function testBadMaxAgeExits1WithoutReading(): void
    {
        $reader = $this->createMock(ScheduledJobStatusReader::class);
        $reader->expects(self::never())->method('read');

        $tester = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(1, $tester->execute(['--max-age' => '0'], self::ERR));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('--max-age must be an integer greater than or equal to 1.', $tester->getErrorOutput(true));
    }

    public function testReaderFailureExits1OnStderr(): void
    {
        $reader = $this->createStub(ScheduledJobStatusReader::class);
        $reader->method('read')->willThrowException(new \RuntimeException('Redis is unavailable; scheduler job status cannot be read.'));

        $tester = new CommandTester(new SchedulerStatusCommand($reader));
        self::assertSame(1, $tester->execute([], self::ERR));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Redis is unavailable', $tester->getErrorOutput(true));
    }

    private function report(string $state): ScheduledJobStatusReport
    {
        return new ScheduledJobStatusReport($state, [
            new ScheduledJobStatus('app:media:reap-jobs', 'tick', 1_700_000_000, 1_700_000_005, 0, 'web-1'),
            new ScheduledJobStatus('app:chat:reap-stuck', 'tick', null, null, null, null),
        ]);
    }
}
