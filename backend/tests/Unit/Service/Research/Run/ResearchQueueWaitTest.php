<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchEventStream;
use App\Service\Research\Run\ResearchOutcome;
use App\Service\Research\Run\ResearchQueueWait;
use App\Service\Research\Run\ResearchRunState;
use App\Service\Research\Run\ResearchStatus;

final class ResearchQueueWaitTest extends ResearchUnitCase
{
    public function testANormalPauseBetweenStepsIsNotQueued(): void
    {
        $state = $this->aged(ResearchQueueWait::AFTER_SECONDS - 1);

        self::assertNull($this->queue()->since($state));
    }

    public function testAStepWithoutAWorkerCountsFromTheLastCheckpoint(): void
    {
        $state = $this->aged(45);

        self::assertSame(45, $this->queue()->since($state));
        self::assertFalse($this->queue()->quiet($state));
    }

    public function testFiveMinutesWithoutAWorkerIsQuiet(): void
    {
        $state = $this->aged(ResearchQueueWait::QUIET_SECONDS);

        self::assertTrue($this->queue()->quiet($state));
    }

    public function testAClaimedStepIsNotQueued(): void
    {
        $state = $this->aged(120);
        self::assertNotNull($this->store->claim($state->ref->id, $state->expectedSeq, 60_000));

        self::assertNull($this->queue()->since($state));
    }

    public function testARunWaitingForThePersonOrFinishedIsNotQueued(): void
    {
        $opened = $this->openRun();
        $waiting = new ResearchRunState($opened->ref, ResearchStatus::WaitingPlan, $opened->ledger, $opened->budget, 2, 1);
        $this->store->save($waiting);
        $this->age($waiting, 600);

        self::assertNull($this->queue()->since($this->reload($waiting)));

        $finished = $this->reload($waiting);
        $finished->finish(ResearchStatus::Stopped, new ResearchOutcome(ResearchOutcome::STOPPED_BY_USER));

        self::assertNull($this->queue()->since($finished));
    }

    public function testTheStreamEndsWithAQueuedFrameThatDoesNotMoveTheCursor(): void
    {
        $body = ResearchEventStream::body([['seq' => 3, 'event' => 'plan', 'data' => []]], 45);

        self::assertSame("id: 3\nevent: plan\ndata: []\n\nevent: queued\ndata: {\"since_seconds\":45}\n\n", $body);
        self::assertSame("id: 3\nevent: plan\ndata: []\n\n", ResearchEventStream::body([['seq' => 3, 'event' => 'plan', 'data' => []]]));
    }

    private function queue(): ResearchQueueWait
    {
        return new ResearchQueueWait($this->store, $this->clock);
    }

    private function aged(int $seconds): ResearchRunState
    {
        $state = $this->openRun();
        $this->age($state, $seconds);

        return $this->reload($state);
    }

    private function reload(ResearchRunState $state): ResearchRunState
    {
        $loaded = $this->store->load($state->ref->id);
        self::assertNotNull($loaded);

        return $loaded;
    }
}
