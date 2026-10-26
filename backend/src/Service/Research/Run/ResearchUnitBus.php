<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

interface ResearchUnitBus
{
    public function dispatch(string $runId, string $unit, int $seq, int $delayMs): void;
}
