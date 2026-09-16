<?php

declare(strict_types=1);

namespace App\Tests\Unit\Command;

use App\Command\ReapStuckChatCommand;
use App\Service\Chat\StuckChatReaper;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

final class ReapStuckChatCommandTest extends TestCase
{
    public function testReportsWhenNothingIsStale(): void
    {
        $reaper = $this->createMock(StuckChatReaper::class);
        $reaper->expects(self::once())->method('reap')->willReturn(['messages' => 0, 'files' => 0]);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $locks = $this->createMock(LockFactory::class);
        $locks->expects(self::once())->method('createLock')->with('chat-stuck-reaper', 120)->willReturn($lock);

        $tester = new CommandTester(new ReapStuckChatCommand($reaper, $locks));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('No stuck chat messages or files to reap.', $tester->getDisplay());
    }

    public function testReportsReapedCounts(): void
    {
        $reaper = $this->createMock(StuckChatReaper::class);
        $reaper->method('reap')->willReturn(['messages' => 2, 'files' => 1]);

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(true);
        $locks = $this->createMock(LockFactory::class);
        $locks->method('createLock')->willReturn($lock);

        $tester = new CommandTester(new ReapStuckChatCommand($reaper, $locks));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Marked 2 stuck message(s) and 1 stuck file(s) as error.', $tester->getDisplay());
    }

    public function testSkipsWhenLockIsHeld(): void
    {
        $reaper = $this->createMock(StuckChatReaper::class);
        $reaper->expects(self::never())->method('reap');

        $lock = $this->createMock(SharedLockInterface::class);
        $lock->method('acquire')->willReturn(false);
        $locks = $this->createMock(LockFactory::class);
        $locks->method('createLock')->willReturn($lock);

        $tester = new CommandTester(new ReapStuckChatCommand($reaper, $locks));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('still active', $tester->getDisplay());
    }
}
