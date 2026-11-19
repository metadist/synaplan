<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Run;

use App\Service\Run\RunUnitBus;

/** Records what a test queued instead of queueing it. */
final class RecordingRunUnitBus implements RunUnitBus
{
    /** @var list<array{runId: string, unit: string, seq: int, delayMs: int}> */
    public array $queued = [];

    public function dispatch(string $runId, string $unit, int $seq, int $delayMs): void
    {
        $this->queued[] = ['runId' => $runId, 'unit' => $unit, 'seq' => $seq, 'delayMs' => $delayMs];
    }
}
