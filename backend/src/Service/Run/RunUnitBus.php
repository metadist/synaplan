<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * Queues the next unit of a run. A unit never holds a worker past one step;
 * the gap keeps one run from filling the queue.
 */
interface RunUnitBus
{
    public function dispatch(string $runId, string $unit, int $seq, int $delayMs): void;
}
