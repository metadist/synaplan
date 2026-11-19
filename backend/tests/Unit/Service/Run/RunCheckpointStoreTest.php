<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunCheckpointStore;

final class RunCheckpointStoreTest extends RunKernelCase
{
    public function testOnlyOneCallerHoldsAUnit(): void
    {
        $run = $this->openRun();

        $first = $this->store->claim($run->id, 1, 1000);
        $second = $this->store->claim($run->id, 1, 1000);

        self::assertNotNull($first);
        self::assertNull($second);
    }

    public function testACheckpointIsFencedByTheClaimToken(): void
    {
        $run = $this->openRun();
        $token = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($token);

        $run->done[] = 'stale';
        self::assertFalse($this->store->checkpoint($run, str_repeat('0', 32)));
        self::assertSame([], $this->stored($run->id)->done);

        self::assertTrue($this->store->checkpoint($run, $token));
        self::assertSame(['stale'], $this->stored($run->id)->done);
        self::assertSame(1, $this->stored($run->id)->checkpointSeq());
        self::assertSame(2, $run->expectedSeq());
    }

    public function testALeaseThatRanOutCannotCheckpointAfterAnotherWorkerTookOver(): void
    {
        $run = $this->openRun();
        $old = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($old);
        $this->clock->sleep(2);
        $new = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($new);

        self::assertFalse($this->store->checkpoint($run, $old));
        self::assertFalse($this->store->renew($run->id, 1, $old, 1000));
        self::assertTrue($this->store->renew($run->id, 1, $new, 1000));
    }

    public function testAWaitingRunKeepsItsCheckpointUntilItEnds(): void
    {
        $run = $this->openRun();
        self::assertSame(-1, $this->keys->ttlMs($this->keyspace->state($run->id)));

        $this->clock->sleep(72 * 3600);
        self::assertNotNull($this->store->load($run->id));

        $token = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($token);
        $run->status = 'completed';
        self::assertTrue($this->store->checkpoint($run, $token));
        self::assertSame(RunCheckpointStore::DEFAULT_RETENTION_HOURS * 3600 * 1000, $this->keys->ttlMs($this->keyspace->state($run->id)));
        self::assertSame([], $this->store->activeRuns());
    }

    public function testUnderClaimRefusesARunThatMovedOn(): void
    {
        $run = $this->openRun();
        $token = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($token);
        self::assertTrue($this->store->checkpoint($run, $token));
        $stale = new TestRunState($run->id, expected: 1);

        $changed = $this->store->underClaim($stale, 1000, static fn (): bool => true);

        self::assertNull($changed);
        self::assertFalse($this->store->isClaimed($run->id, 1));
    }

    public function testExpiredClaimsListOnlyUnitsThatWereNotWritten(): void
    {
        $lost = $this->openRun();
        $written = $this->openRun();
        self::assertNotNull($this->store->claim($lost->id, 1, 1000));
        $token = $this->store->claim($written->id, 1, 1000);
        self::assertNotNull($token);
        self::assertTrue($this->store->checkpoint($written, $token));
        $this->clock->sleep(2);

        $expired = $this->store->expiredClaims();

        self::assertCount(1, $expired);
        self::assertSame($lost->id, $expired[0]['runId']);
        self::assertSame(1, $expired[0]['seq']);
    }

    public function testLostClaimsCountPerUnitAndStartOverForTheNext(): void
    {
        $run = $this->openRun();

        self::assertSame(1, $this->store->noteLostClaim($run->id, 1));
        self::assertSame(2, $this->store->noteLostClaim($run->id, 1));
        self::assertSame($this->clock->now()->getTimestamp(), $this->store->lastRedispatchAt($run->id, 1));
        self::assertSame(0, $this->store->lostClaims($run->id, 2));
        self::assertNull($this->store->lastRedispatchAt($run->id, 2));
    }

    public function testAStopIsAFlagTheCheckpointCannotOverwrite(): void
    {
        $run = $this->openRun();
        $token = $this->store->claim($run->id, 1, 1000);
        self::assertNotNull($token);
        $this->store->requestCancel($run->id);

        self::assertTrue($this->store->checkpoint($run, $token));
        self::assertTrue($this->store->cancelRequested($run->id));
    }

    public function testAStoredRunUnderAnotherIdIsRefused(): void
    {
        $run = $this->openRun();
        $other = $this->openRun();
        $this->keys->set($this->keyspace->state($run->id), (string) $this->keys->get($this->keyspace->state($other->id)), null);

        $this->expectException(\UnexpectedValueException::class);
        $this->store->load($run->id);
    }

    public function testAKindWithAnActiveExpirySlidesItOnEveryWrite(): void
    {
        $store = new RunCheckpointStore($this->keys, $this->keyspace, new TestRunState(), $this->clock, 48, 24);
        $run = new TestRunState('01JD0000000000000000000000', updated: $this->clock->now()->getTimestamp());
        $store->open($run);
        self::assertSame(24 * 3600 * 1000, $this->keys->ttlMs($this->keyspace->state($run->id)));

        $this->clock->sleep(3600);
        $token = $store->claim($run->id, 1, 1000);
        self::assertNotNull($token);
        self::assertTrue($store->checkpoint($run, $token));

        self::assertSame(24 * 3600 * 1000, $this->keys->ttlMs($this->keyspace->state($run->id)));
        self::assertSame($this->clock->now()->getTimestamp() + 24 * 3600, $run->expiresAt);
        $this->clock->sleep(24 * 3600);
        self::assertNull($store->load($run->id));
    }

    public function testAnActiveExpiryOutsideTheWindowIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunCheckpointStore($this->keys, $this->keyspace, new TestRunState(), $this->clock, 48, 169);
    }

    public function testTheWrittenHookSeesEveryOpenAndCheckpoint(): void
    {
        $seen = [];
        $store = new RunCheckpointStore(
            $this->keys,
            $this->keyspace,
            new TestRunState(),
            $this->clock,
            written: static function (TestRunState $state) use (&$seen): void {
                $seen[] = $state->checkpointSeq();
            },
        );
        $run = new TestRunState('01JD0000000000000000000000', updated: $this->clock->now()->getTimestamp());
        $store->open($run);
        $token = $store->claim($run->id, 1, 1000);
        self::assertNotNull($token);
        self::assertTrue($store->checkpoint($run, $token));

        self::assertSame([0, 1], $seen);
    }

    public function testAStoredValueTheCodecDoesNotReadIsNoRun(): void
    {
        $run = $this->openRun();
        $this->keys->set($this->keyspace->state($run->id), TestRunState::GONE, null);

        self::assertNull($this->store->load($run->id));
    }
}
