<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Run;

use App\Service\Chat\Run\ChatRun;
use PHPUnit\Framework\TestCase;

/**
 * The run record travels through Redis as JSON, so a lost or mistyped field
 * silently breaks a re-attach: a dropped ownerKey would fail authorization for
 * the rightful owner, a dropped status would leave a finished turn looking as
 * if it were still generating.
 */
final class ChatRunTest extends TestCase
{
    public function testSurvivesTheRedisRoundTrip(): void
    {
        $run = new ChatRun('run-1', 'user:42', 7, 'track-abc');
        $run->markTerminal(ChatRun::STATUS_COMPLETE)
            ->setMessageId(915)
            ->setLastSeq(12)
            ->markTruncated();

        $restored = ChatRun::fromArray(json_decode((string) json_encode($run->toArray()), true));

        self::assertSame('run-1', $restored->getRunId());
        self::assertSame('user:42', $restored->getOwnerKey());
        self::assertSame(7, $restored->getChatId());
        self::assertSame('track-abc', $restored->getTrackId());
        self::assertSame(ChatRun::STATUS_COMPLETE, $restored->getStatus());
        self::assertSame(915, $restored->getMessageId());
        self::assertSame(12, $restored->getLastSeq());
        self::assertTrue($restored->isTruncated());
        self::assertSame($run->getCreated(), $restored->getCreated());
        self::assertSame($run->getUpdated(), $restored->getUpdated());
    }

    public function testAnIncognitoStyleRunWithoutChatStaysChatless(): void
    {
        $run = ChatRun::fromArray(['runId' => 'run-2', 'ownerKey' => 'guest:s', 'chatId' => null, 'trackId' => 't']);

        self::assertNull($run->getChatId());
        self::assertFalse($run->isTerminal());
        self::assertSame(ChatRun::STATUS_RUNNING, $run->getStatus());
    }

    public function testOnlyRunningCountsAsNonTerminal(): void
    {
        foreach ([ChatRun::STATUS_COMPLETE, ChatRun::STATUS_ERROR, ChatRun::STATUS_CANCELLED] as $status) {
            $run = new ChatRun('run', 'user:1', 1, 't');
            $run->markTerminal($status);

            self::assertTrue($run->isTerminal(), $status.' must end the run');
            self::assertSame($status, $run->getStatus());
        }
    }

    public function testAnUnknownTerminalStatusDegradesToError(): void
    {
        // A caller passing something unexpected must still close the run —
        // a run stuck on "running" would make every re-attach wait for a
        // heartbeat that never ticks again.
        $run = new ChatRun('run', 'user:1', 1, 't');
        $run->markTerminal('whatever');

        self::assertSame(ChatRun::STATUS_ERROR, $run->getStatus());
        self::assertTrue($run->isTerminal());
    }
}
