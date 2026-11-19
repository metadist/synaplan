<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunClaim;
use App\Service\Run\RunDelivery;
use App\Service\Run\RunNotFound;
use App\Service\Run\RunUnit;

final class RunUnitTest extends RunKernelCase
{
    public function testAUnitRunsOnceAndQueuesTheNextAfterTheGap(): void
    {
        $handler = new TestRunUnitHandler();
        $run = $this->openRun();

        (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);

        self::assertSame(1, $handler->paidCalls);
        self::assertSame(['step'], $this->stored($run->id)->done);
        self::assertSame(2, $this->stored($run->id)->expectedSeq());
        self::assertSame([['runId' => $run->id, 'unit' => 'finish', 'seq' => 2, 'delayMs' => RunUnit::GAP_MS]], $this->bus->queued);
    }

    public function testOfTwoWorkersOnTheSameUnitOnlyOneExecutes(): void
    {
        $handler = new TestRunUnitHandler();
        $unit = new RunUnit($this->store, $handler, $this->bus);
        $run = $this->openRun();
        // The duplicate delivery claims and finishes between the first one's load and claim.
        $this->keys->beforeClaim = function () use ($unit, $run): void {
            $unit->handle($run->id, 'step', 1);
        };

        $unit->handle($run->id, 'step', 1);

        self::assertSame(1, $handler->paidCalls);
        self::assertSame(['step'], $this->stored($run->id)->done);
        self::assertCount(1, $this->bus->queued);
    }

    public function testADuplicateOfAWrittenUnitReturnsBeforeAnyPaidCall(): void
    {
        $handler = new TestRunUnitHandler();
        $unit = new RunUnit($this->store, $handler, $this->bus);
        $run = $this->openRun();

        $unit->handle($run->id, 'step', 1);
        $unit->handle($run->id, 'step', 1);

        self::assertSame(1, $handler->paidCalls);
    }

    public function testAUnitWhoseLeaseRanOutCannotWriteOrQueue(): void
    {
        $handler = new TestRunUnitHandler();
        $run = $this->openRun();
        $handler->duringUnit = function (): void {
            $this->clock->sleep((TestRunUnitHandler::LEASE_MS / 1000) + 1);
        };

        (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);

        self::assertSame([], $this->stored($run->id)->done);
        self::assertSame(1, $this->stored($run->id)->expectedSeq());
        self::assertSame([], $this->bus->queued);
    }

    public function testAStopBeforeTheUnitEndsTheRunWithoutAPaidCall(): void
    {
        $handler = new TestRunUnitHandler();
        $run = $this->openRun();
        $this->store->requestCancel($run->id);

        (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);

        self::assertSame(0, $handler->paidCalls);
        self::assertSame('stopped', $this->stored($run->id)->status);
        self::assertSame([], $this->bus->queued);
    }

    public function testAStopDuringTheUnitIsSeenBeforeTheNextPaidCall(): void
    {
        $handler = new TestRunUnitHandler();
        $run = $this->openRun();
        $handler->duringUnit = function (TestRunState $state, RunClaim $claim): void {
            $this->store->requestCancel($claim->runId);
        };

        (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);

        self::assertSame(0, $handler->paidCalls);
        self::assertSame('stopped', $this->stored($run->id)->status);
    }

    public function testARunThatDisappearedReleasesItsClaim(): void
    {
        $handler = new TestRunUnitHandler();
        $run = $this->openRun();
        $handler->duringUnit = static function (): never {
            throw new RunNotFound('gone');
        };

        (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);

        self::assertFalse($this->store->isClaimed($run->id, 1));
        self::assertSame([], $this->bus->queued);
    }

    public function testAFailedUnitKeepsItsClaimForTheReaper(): void
    {
        $handler = new TestRunUnitHandler();
        $run = $this->openRun();
        $handler->duringUnit = static function (): never {
            throw new \RuntimeException('provider down');
        };

        try {
            (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);
            self::fail('The failure should reach the queue.');
        } catch (\RuntimeException) {
        }

        self::assertTrue($this->store->isClaimed($run->id, 1));
    }

    public function testUnknownUnitsIdsAndStaleSequencesAreIgnored(): void
    {
        $handler = new TestRunUnitHandler();
        $unit = new RunUnit($this->store, $handler, $this->bus);
        $run = $this->openRun();

        $unit->handle($run->id, 'drop_tables', 1);
        $unit->handle('run:state:'.$run->id, 'step', 1);
        $unit->handle($run->id, 'step', 2);
        $unit->handle($run->id, 'step', 0);

        self::assertSame(0, $handler->paidCalls);
        self::assertFalse($this->store->isClaimed($run->id, 1));
    }

    public function testAFinishedRunIsDeliveredIntoItsChatWithTheTabClosed(): void
    {
        $handler = new TestRunUnitHandler(new RunDelivery($this->keys, $this->keyspace));
        $unit = new RunUnit($this->store, $handler, $this->bus);
        $run = $this->openRun();

        // Nobody listens on a stream: the queue alone drives the run to its end.
        $unit->handle($run->id, 'step', 1);
        $unit->handle($run->id, 'finish', 2);
        $unit->handle($run->id, 'finish', 2);
        $handler->deliver($this->stored($run->id));

        self::assertSame([$run->id.': completed'], $handler->chat);
    }
}
