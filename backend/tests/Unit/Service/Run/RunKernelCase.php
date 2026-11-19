<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunCheckpointStore;
use App\Service\Run\RunIds;
use App\Service\Run\RunKeyspace;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Shared wiring for the run kernel tests: one clock, one in-memory Redis,
 * one store, and a bus that records what it queued.
 */
abstract class RunKernelCase extends TestCase
{
    protected MockClock $clock;
    protected MemoryRunKeys $keys;
    protected RunKeyspace $keyspace;
    protected RunCheckpointStore $store;
    protected RecordingRunUnitBus $bus;

    protected function setUp(): void
    {
        $this->clock = new MockClock('2026-11-19 12:00:00');
        $this->keys = new MemoryRunKeys($this->clock);
        $this->keyspace = RunKeyspace::runs();
        $this->store = new RunCheckpointStore($this->keys, $this->keyspace, new TestRunState(), $this->clock);
        $this->bus = new RecordingRunUnitBus();
    }

    protected function openRun(string $status = 'running'): TestRunState
    {
        $state = new TestRunState(RunIds::newId($this->clock), $status, updated: $this->clock->now()->getTimestamp());
        $this->store->open($state);

        return $state;
    }

    protected function stored(string $runId): TestRunState
    {
        $state = $this->store->load($runId);
        self::assertInstanceOf(TestRunState::class, $state);

        return $state;
    }
}
