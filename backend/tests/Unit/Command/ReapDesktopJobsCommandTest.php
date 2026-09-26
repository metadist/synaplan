<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReapDesktopJobsCommand;
use App\Entity\DesktopJob;
use App\Service\Desktop\DesktopAgentConfig;
use App\Service\Desktop\DesktopJobResultNotifier;
use App\Service\Desktop\DesktopJobStore;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class ReapDesktopJobsCommandTest extends TestCase
{
    private DesktopJobStore&MockObject $jobStore;
    private DesktopAgentConfig&MockObject $config;
    private LockFactory&MockObject $lockFactory;
    private DesktopJobResultNotifier&MockObject $notifier;

    protected function setUp(): void
    {
        $this->jobStore = $this->createMock(DesktopJobStore::class);
        $this->config = $this->createMock(DesktopAgentConfig::class);
        $this->lockFactory = $this->createMock(LockFactory::class);
        $this->notifier = $this->createMock(DesktopJobResultNotifier::class);
    }

    public function testReaperIsInertWhenFeatureDisabled(): void
    {
        $this->config->method('isEnabled')->willReturn(false);
        // Flag off means idle, not broken (C8): no lock, no work.
        $this->lockFactory->expects(self::never())->method('createLock');
        $this->jobStore->expects(self::never())->method('requeueExpiredLeases');

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('disabled', $tester->getDisplay());
    }

    public function testReaperRequeuesWhenEnabled(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $this->lockFactory->method('createLock')->willReturn($lock);

        $failed = (new DesktopJob())->setOwnerId(1)->setStatus(DesktopJob::STATUS_FAILED);
        $this->jobStore->expects(self::once())
            ->method('requeueExpiredLeases')
            ->willReturn(['requeued' => 2, 'failed' => 1, 'failedJobs' => [$failed]]);
        $this->notifier->expects(self::once())->method('notify')->with($failed);

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('Requeued 2', $tester->getDisplay());
    }

    public function testReaperSkipsWhenLockHeld(): void
    {
        $this->config->method('isEnabled')->willReturn(true);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);
        $this->lockFactory->method('createLock')->willReturn($lock);

        $this->jobStore->expects(self::never())->method('requeueExpiredLeases');

        $tester = $this->runCommand();

        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('still active', $tester->getDisplay());
    }

    private function runCommand(): CommandTester
    {
        $command = new ReapDesktopJobsCommand($this->jobStore, $this->config, $this->lockFactory, $this->notifier);
        $tester = new CommandTester($command);
        $tester->execute([]);

        return $tester;
    }
}
