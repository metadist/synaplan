<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunEventLog;
use App\Service\Run\RunNotFound;

final class RunEventLogTest extends RunKernelCase
{
    public function testEventsAreNumberedAndReplayedAfterTheLastSeen(): void
    {
        $log = $this->log();
        $run = $this->openRun();

        self::assertSame(1, $log->append($run->id, 'progress', ['step' => 'a']));
        self::assertSame(2, $log->append($run->id, 'progress', ['step' => 'b']));
        self::assertSame(3, $log->append($run->id, 'done', []));

        self::assertSame([
            ['seq' => 2, 'event' => 'progress', 'data' => ['step' => 'b']],
            ['seq' => 3, 'event' => 'done', 'data' => []],
        ], $log->replay($run->id, 1));
        self::assertSame([], $log->replay($run->id, 3));
    }

    public function testAnUnknownNameIsRefused(): void
    {
        $run = $this->openRun();

        $this->expectException(\InvalidArgumentException::class);
        $this->log()->append($run->id, 'chat_chunk', []);
    }

    public function testAGoneRunTakesNoEvents(): void
    {
        $run = $this->openRun();
        $this->keys->delete($this->keyspace->state($run->id));

        $this->expectException(RunNotFound::class);
        $this->log()->append($run->id, 'progress', []);
    }

    public function testAnInvalidIdNeverReachesAKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->log()->replay('../state:x', 0);
    }

    public function testANegativeCursorIsRefused(): void
    {
        $run = $this->openRun();

        $this->expectException(\InvalidArgumentException::class);
        $this->log()->replay($run->id, -1);
    }

    private function log(): RunEventLog
    {
        return new RunEventLog($this->keys, $this->keyspace, ['progress', 'done']);
    }
}
