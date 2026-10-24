<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

final readonly class SystemResearchClock implements ResearchClock
{
    public function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
