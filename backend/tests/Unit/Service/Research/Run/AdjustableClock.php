<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Research\Run;

use App\Service\Research\Run\ResearchClock;

final class AdjustableClock implements ResearchClock
{
    public function __construct(private int $nowMs = 1_700_000_000_000)
    {
    }

    public function nowMs(): int
    {
        return $this->nowMs;
    }

    public function advanceMs(int $ms): void
    {
        $this->nowMs += $ms;
    }
}
