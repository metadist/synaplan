<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ClaimSchedulerSlotCommand;
use App\Service\Scheduler\SchedulerSlotPolicy;
use App\Service\Scheduler\SchedulerSlotSnapshot;
use App\Service\Scheduler\SchedulerSlotStore;
use Doctrine\DBAL\Exception\RetryableException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;

final class ClaimSchedulerSlotCommandTest extends TestCase
{
    private const ERR = ['capture_stderr_separately' => true];

    public function testClaimedIntervalPrintsNothingAndExits0(): void
    {
        $now = 1_700_000_000;
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->expects(self::once())->method('read')
            ->with(SchedulerSlotStore::SETTING_HOURLY)
            ->willReturn(new SchedulerSlotSnapshot(false, null, null));
        $store->expects(self::once())->method('claim')
            ->with(SchedulerSlotStore::SETTING_HOURLY, false, null, $now)
            ->willReturn(true);

        $tester = $this->tester($store, $now);
        self::assertSame(0, $tester->execute([
            'slot' => 'hourly',
            '--interval' => '3600',
        ], self::ERR));
        self::assertSame('', $tester->getDisplay());
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testNotDueIntervalPrintsOnlyTheWait(): void
    {
        $now = 1_700_000_000;
        $last = $now - 100;
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->method('read')->willReturn(new SchedulerSlotSnapshot(true, $last, (string) $last));
        $store->expects(self::never())->method('claim');

        $tester = $this->tester($store, $now);
        self::assertSame(3, $tester->execute([
            'slot' => 'health',
            '--interval' => '3600',
        ], self::ERR));
        self::assertSame("3500\n", $tester->getDisplay(true));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testAtModePrintsSecondsUntilTheNextUtcTime(): void
    {
        $now = new \DateTimeImmutable('2026-10-01 04:00:00', new \DateTimeZone('UTC'));
        $last = new \DateTimeImmutable('2026-10-01 03:30:00', new \DateTimeZone('UTC'));
        $next = new \DateTimeImmutable('2026-10-02 03:30:00', new \DateTimeZone('UTC'));
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->expects(self::once())->method('read')
            ->with(SchedulerSlotStore::SETTING_DAILY)
            ->willReturn(new SchedulerSlotSnapshot(true, $last->getTimestamp(), (string) $last->getTimestamp()));
        $store->expects(self::never())->method('claim');

        $tester = new CommandTester(new ClaimSchedulerSlotCommand(
            $store,
            new SchedulerSlotPolicy(),
            new MockClock($now),
        ));
        self::assertSame(3, $tester->execute([
            'slot' => 'daily',
            '--at' => '03:30',
        ], self::ERR));
        self::assertSame(($next->getTimestamp() - $now->getTimestamp())."\n", $tester->getDisplay(true));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testFreshInstallAtModeClaimsImmediately(): void
    {
        $now = (new \DateTimeImmutable('2026-10-01 01:00:00', new \DateTimeZone('UTC')))->getTimestamp();
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->method('read')->willReturn(new SchedulerSlotSnapshot(false, null, null));
        $store->expects(self::once())->method('claim')
            ->with(SchedulerSlotStore::SETTING_DAILY, false, null, $now)
            ->willReturn(true);

        $tester = $this->tester($store, $now);
        self::assertSame(0, $tester->execute(['slot' => 'daily', '--at' => '03:30'], self::ERR));
        self::assertSame('', $tester->getDisplay());
    }

    public function testLostRaceWaitsSixtySeconds(): void
    {
        $store = $this->createStub(SchedulerSlotStore::class);
        $store->method('read')->willReturn(new SchedulerSlotSnapshot(false, null, null));
        $store->method('claim')->willReturn(false);

        $tester = $this->tester($store, 1_700_000_000);
        self::assertSame(3, $tester->execute(['slot' => 'hourly', '--interval' => '60'], self::ERR));
        self::assertSame("60\n", $tester->getDisplay(true));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testRetryableReadIsNotDue(): void
    {
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->method('read')->willThrowException(self::galeraConflict());
        $store->expects(self::never())->method('claim');

        $tester = $this->tester($store, 1_700_000_000);
        self::assertSame(3, $tester->execute(['slot' => 'health', '--interval' => '900'], self::ERR));
        self::assertSame("60\n", $tester->getDisplay(true));
        self::assertSame('', $tester->getErrorOutput());
    }

    public function testDatabaseErrorExits1OnStderr(): void
    {
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->method('read')->willThrowException(new \RuntimeException('connection refused'));
        $store->expects(self::never())->method('claim');

        $tester = $this->tester($store, 1_700_000_000);
        self::assertSame(1, $tester->execute(['slot' => 'hourly', '--interval' => '3600'], self::ERR));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('connection refused', $tester->getErrorOutput(true));
    }

    /**
     * @param array<string, string> $input
     */
    #[DataProvider('invalidInput')]
    public function testInvalidInputExits1WithoutAClaim(array $input, string $stderrFragment): void
    {
        $store = $this->createMock(SchedulerSlotStore::class);
        $store->expects(self::never())->method('read');
        $store->expects(self::never())->method('claim');

        $tester = $this->tester($store, 1_700_000_000);
        self::assertSame(1, $tester->execute($input, self::ERR));
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString($stderrFragment, $tester->getErrorOutput(true));
    }

    /**
     * @return iterable<string, array{0: array<string, string>, 1: string}>
     */
    public static function invalidInput(): iterable
    {
        yield 'unknown slot' => [['slot' => 'weekly', '--interval' => '60'], 'Unknown scheduler slot "weekly"'];
        yield 'neither mode' => [['slot' => 'hourly'], 'Pass exactly one of --interval or --at.'];
        yield 'both modes' => [['slot' => 'hourly', '--interval' => '60', '--at' => '03:30'], 'Pass exactly one of --interval or --at.'];
        yield 'zero interval' => [['slot' => 'hourly', '--interval' => '0'], '--interval must be an integer greater than or equal to 1.'];
        yield 'fractional interval' => [['slot' => 'health', '--interval' => '1.5'], '--interval must be an integer greater than or equal to 1.'];
        yield 'padded interval' => [['slot' => 'health', '--interval' => '01'], '--interval must be an integer greater than or equal to 1.'];
        yield 'unpadded at' => [['slot' => 'daily', '--at' => '3:30'], '--at must be a UTC time in HH:MM.'];
        yield 'at with seconds' => [['slot' => 'daily', '--at' => '03:30:00'], '--at must be a UTC time in HH:MM.'];
        yield 'hour 24' => [['slot' => 'daily', '--at' => '24:00'], '--at must be a UTC time in HH:MM.'];
    }

    private function tester(SchedulerSlotStore $store, int $now): CommandTester
    {
        return new CommandTester(new ClaimSchedulerSlotCommand(
            $store,
            new SchedulerSlotPolicy(),
            new MockClock(new \DateTimeImmutable('@'.$now)),
        ));
    }

    private static function galeraConflict(): \Throwable
    {
        return new class('Galera certification conflict') extends \RuntimeException implements RetryableException {
        };
    }
}
