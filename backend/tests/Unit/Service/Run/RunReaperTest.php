<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunDelivery;
use App\Service\Run\RunReaper;
use App\Service\Run\RunUnit;
use Psr\Log\NullLogger;

final class RunReaperTest extends RunKernelCase
{
    public function testALostUnitIsRedispatchedOnceThenTheRunIsInterrupted(): void
    {
        $policy = new TestRunReapPolicy();
        $run = $this->openRun();

        self::assertNotNull($this->store->claim($run->id, 1, 1000));
        $this->clock->sleep(2);
        $first = $this->reaper($policy)->reap();

        self::assertSame(['redispatched' => 1, 'interrupted' => 0, 'waited' => 0], $first);
        self::assertSame([['runId' => $run->id, 'unit' => 'step', 'seq' => 1, 'delayMs' => 0]], $this->bus->queued);

        self::assertNotNull($this->store->claim($run->id, 1, 1000));
        $this->clock->sleep(2);
        $second = $this->reaper($policy)->reap();

        self::assertSame(['redispatched' => 0, 'interrupted' => 1, 'waited' => 0], $second);
        self::assertSame('interrupted', $this->stored($run->id)->status);
        self::assertSame([$run->id], $policy->closed);
        self::assertCount(1, $this->bus->queued);
    }

    public function testAStalledRunIsRedispatchedThenInterrupted(): void
    {
        $policy = new TestRunReapPolicy();
        $run = $this->openRun();

        $this->clock->sleep(RunReaper::STALL_SECONDS - 1);
        self::assertSame(['redispatched' => 0, 'interrupted' => 0, 'waited' => 0], $this->reaper($policy)->reap());

        $this->clock->sleep(1);
        self::assertSame(1, $this->reaper($policy)->reap()['redispatched']);
        self::assertSame(0, $this->reaper($policy)->reap()['redispatched']);

        $this->clock->sleep(RunReaper::STALL_SECONDS);
        self::assertSame(1, $this->reaper($policy)->reap()['interrupted']);
        self::assertSame('interrupted', $this->stored($run->id)->status);
    }

    public function testAWaitingRunIsLeftToItsPolicy(): void
    {
        $policy = new TestRunReapPolicy();
        $run = $this->openRun('waiting');

        $this->clock->sleep(RunReaper::STALL_SECONDS);
        self::assertSame(['redispatched' => 0, 'interrupted' => 0, 'waited' => 0], $this->reaper($policy)->reap());

        $this->clock->sleep(TestRunReapPolicy::WAIT_SECONDS);
        self::assertSame(1, $this->reaper($policy)->reap()['waited']);
        self::assertSame([$run->id], $policy->waited);
        self::assertSame([], $this->bus->queued);
    }

    public function testAClaimOfAFinishedRunIsOnlyReleased(): void
    {
        $policy = new TestRunReapPolicy();
        $run = $this->openRun();
        $token = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($token);
        $run->status = 'completed';
        // The run ended through another path while this claim's index entry stayed.
        $this->keys->set($this->keyspace->state($run->id), $run->encode(1, 0, 0), null);
        $this->clock->sleep(2);

        self::assertSame(['redispatched' => 0, 'interrupted' => 0, 'waited' => 0], $this->reaper($policy)->reap());
        self::assertSame([], $this->store->expiredClaims());
    }

    public function testARunTheReaperEndsIsDeliveredOnceEvenAfterTheWorkerReturns(): void
    {
        $handler = new TestRunUnitHandler(new RunDelivery($this->keys, $this->keyspace));
        $policy = new TestRunReapPolicy($handler);
        $run = $this->openRun();
        $this->store->noteLostClaim($run->id, 1);
        self::assertNotNull($this->store->claim($run->id, 1, 1000));
        $this->clock->sleep(2);

        $this->reaper($policy)->reap();
        (new RunUnit($this->store, $handler, $this->bus))->handle($run->id, 'step', 1);
        $handler->deliver($this->stored($run->id));

        self::assertSame([$run->id.': interrupted'], $handler->chat);
        self::assertSame(0, $handler->paidCalls);
    }

    public function testOneBrokenRunDoesNotStopTheOthers(): void
    {
        $policy = new TestRunReapPolicy();
        $broken = $this->openRun();
        $this->keys->set($this->keyspace->state($broken->id), 'not json', null);
        $run = $this->openRun();
        $this->clock->sleep(RunReaper::STALL_SECONDS);

        self::assertSame(1, $this->reaper($policy)->reap()['redispatched']);
        self::assertSame($run->id, $this->bus->queued[0]['runId']);
    }

    private function reaper(TestRunReapPolicy $policy): RunReaper
    {
        return new RunReaper($this->store, $policy, $this->bus, $this->clock, new NullLogger(), TestRunUnitHandler::LEASE_MS);
    }
}
