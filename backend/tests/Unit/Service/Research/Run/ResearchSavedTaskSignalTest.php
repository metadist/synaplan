<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Message\ContinueSavedTaskAfterResearch;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchSavedTaskSignal;
use App\Service\Research\Run\ResearchStatus;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class ResearchSavedTaskSignalTest extends ResearchUnitCase
{
    public function testAFinishedRunQueuesTheHandOverWithItsReportFile(): void
    {
        $state = $this->openRun();
        $state->savedTaskRunId = 9;
        $state->savedTaskNodeId = 'step_1';
        $state->reportFile = ['path' => '/api/v1/files/uploads/r.md', 'type' => 'text/markdown', 'local_path' => 'r.md'];
        $state->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $sent = [];

        $this->signal($sent)->after($state);

        self::assertCount(1, $sent);
        self::assertSame($state->ref->id, $sent[0]->runId());
        self::assertSame(7, $sent[0]->userId());
        self::assertSame(9, $sent[0]->taskRunId());
        self::assertSame('step_1', $sent[0]->nodeId());
        self::assertSame('r.md', $sent[0]->reportFile()['local_path'] ?? null);
        self::assertFalse($sent[0]->deleted());
    }

    public function testARunThatIsStillGoingOrNotLinkedQueuesNothing(): void
    {
        $linked = $this->openRun();
        $linked->savedTaskRunId = 9;
        $linked->savedTaskNodeId = 'step_1';
        $chat = $this->openRun();
        $chat->finish(ResearchStatus::Complete, new ResearchOutcome(ResearchOutcome::COMPLETE));
        $sent = [];

        $this->signal($sent)->after($linked);
        $this->signal($sent)->after($chat);
        $this->signal($sent)->deleted($chat);

        self::assertSame([], $sent);
    }

    public function testADeletedRunStillReachesItsSavedTask(): void
    {
        $state = $this->openRun();
        $state->savedTaskRunId = 9;
        $state->savedTaskNodeId = 'step_1';
        $sent = [];

        $this->signal($sent)->deleted($state);

        self::assertCount(1, $sent);
        self::assertTrue($sent[0]->deleted());
    }

    /**
     * @param list<ContinueSavedTaskAfterResearch> $sent
     */
    private function signal(array &$sent): ResearchSavedTaskSignal
    {
        $bus = $this->createStub(MessageBusInterface::class);
        $bus->method('dispatch')->willReturnCallback(static function (object $message) use (&$sent): Envelope {
            if ($message instanceof ContinueSavedTaskAfterResearch) {
                $sent[] = $message;
            }

            return new Envelope($message);
        });

        return new ResearchSavedTaskSignal($bus, new NullLogger());
    }
}
