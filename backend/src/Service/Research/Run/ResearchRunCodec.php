<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Service\Run\RunStateCodec;

/**
 * Reads a stored research run for the run kernel. A form that cannot be read
 * counts as no run, as it always has for research.
 */
final readonly class ResearchRunCodec implements RunStateCodec
{
    public function decode(string $stored): ?ResearchRunState
    {
        return ResearchRunState::decode($stored);
    }
}
