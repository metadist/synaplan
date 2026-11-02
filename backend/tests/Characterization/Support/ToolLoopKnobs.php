<?php

declare(strict_types=1);

namespace App\Tests\Characterization\Support;

/**
 * Per-case switches the scripted doubles read. One object, mutated between cases.
 */
final class ToolLoopKnobs
{
    public bool $approvals = false;
    public int $maxIterations = 8;
}
