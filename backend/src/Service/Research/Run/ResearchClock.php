<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

interface ResearchClock
{
    public function nowMs(): int;
}
