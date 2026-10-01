<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\ProcessEmailsCommand;
use App\Service\InboundEmailService;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class ProcessEmailsCommandTest extends TestCase
{
    public function testSkipsWhenTheMailboxLockIsHeld(): void
    {
        $emails = $this->createMock(InboundEmailService::class);
        $emails->expects(self::never())->method('processMailhogEmails');

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::never())->method('warning');
        $logger->expects(self::once())->method('info')
            ->with(self::stringContains('inbound smart mailbox'));

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);
        $lock->expects(self::never())->method('release');

        $locks = $this->createMock(LockFactory::class);
        $locks->expects(self::once())->method('createLock')
            ->with('inbound-smart-mailbox', 300)
            ->willReturn($lock);

        $tester = new CommandTester(new ProcessEmailsCommand(
            $emails,
            $logger,
            'https://example.test',
            $locks,
        ));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Previous inbound smart mailbox process is still running.', $tester->getDisplay());
        self::assertStringNotContainsString('Email Processing Service', $tester->getDisplay());
    }

    public function testProcessesMailAndReleasesTheLock(): void
    {
        $emails = $this->createMock(InboundEmailService::class);
        $emails->expects(self::once())->method('processMailhogEmails')
            ->with('https://example.test/api/v1/webhooks/email', true)
            ->willReturn(['total' => 0, 'processed' => 0, 'failed' => 0, 'errors' => []]);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lock->expects(self::once())->method('release');

        $locks = $this->createMock(LockFactory::class);
        $locks->expects(self::once())->method('createLock')->with('inbound-smart-mailbox', 300)->willReturn($lock);

        $tester = new CommandTester(new ProcessEmailsCommand(
            $emails,
            $this->createStub(LoggerInterface::class),
            'https://example.test',
            $locks,
        ));

        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No emails found.', $tester->getDisplay());
    }
}
