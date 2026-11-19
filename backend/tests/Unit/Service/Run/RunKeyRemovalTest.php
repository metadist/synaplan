<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunDelivery;
use App\Service\Run\RunEventLog;
use App\Service\Run\RunKeyRemoval;

final class RunKeyRemovalTest extends RunKernelCase
{
    public function testEveryKeyOfTheRunGoesAndTheOtherRunStays(): void
    {
        $run = $this->openRun();
        $other = $this->openRun();
        foreach ([$run, $other] as $each) {
            self::assertNotNull($this->store->claim($each->id, 1, 1000));
            (new RunEventLog($this->keys, $this->keyspace, ['progress']))->append($each->id, 'progress', []);
            $this->store->noteLostClaim($each->id, 1);
            $this->store->requestCancel($each->id);
            (new RunDelivery($this->keys, $this->keyspace))->once($each->id, static function (): void {});
        }
        // A broken stored form must not keep the run's keys alive.
        $this->keys->set($this->keyspace->state($run->id), 'not json', null);

        (new RunKeyRemoval($this->keys, $this->keyspace))->delete($run->id);

        foreach ($this->keys->keys() as $key) {
            self::assertStringNotContainsString($run->id, $key);
        }
        self::assertSame([$other->id], $this->keys->members($this->keyspace->active()));
        self::assertCount(1, $this->keys->zRangeByScore($this->keyspace->claims(), \PHP_INT_MAX));
        self::assertNotNull($this->store->load($other->id));
    }

    public function testAnInvalidIdIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new RunKeyRemoval($this->keys, $this->keyspace))->delete('*');
    }
}
