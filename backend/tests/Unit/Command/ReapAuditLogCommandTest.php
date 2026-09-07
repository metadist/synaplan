<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReapAuditLogCommand;
use App\Repository\AuditLogEntryRepository;
use App\Service\Iam\IamConfig;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class ReapAuditLogCommandTest extends TestCase
{
    private IamConfig&MockObject $iamConfig;
    private AuditLogEntryRepository&MockObject $audit;
    private LockFactory&MockObject $lockFactory;

    protected function setUp(): void
    {
        $this->iamConfig = $this->createMock(IamConfig::class);
        $this->audit = $this->createMock(AuditLogEntryRepository::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
    }

    public function testZeroRetentionKeepsEverything(): void
    {
        $this->iamConfig->method('auditRetentionDays')->willReturn(0);
        $this->lockFactory->expects(self::never())->method('createLock');
        $this->audit->expects(self::never())->method('deleteOlderThan');

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('keep forever', $tester->getDisplay());
    }

    public function testDeletesRowsOlderThanRetention(): void
    {
        $this->iamConfig->method('auditRetentionDays')->willReturn(365);
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $this->lockFactory->method('createLock')->willReturn($lock);
        $this->audit->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(static function (int $cutoff): bool {
                $expected = time() - (365 * 86400);

                return abs($cutoff - $expected) < 3;
            }))
            ->willReturn(4);

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Deleted 4', $tester->getDisplay());
    }

    public function testBoundaryDayIsExpired(): void
    {
        $this->iamConfig->method('auditRetentionDays')->willReturn(1);
        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $this->lockFactory->method('createLock')->willReturn($lock);
        $this->audit->expects(self::once())
            ->method('deleteOlderThan')
            ->with(self::callback(static function (int $cutoff): bool {
                return $cutoff <= time() - 86400;
            }))
            ->willReturn(0);

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('No expired', $tester->getDisplay());
    }

    private function runCommand(): CommandTester
    {
        $command = new ReapAuditLogCommand($this->iamConfig, $this->audit, $this->lockFactory);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
