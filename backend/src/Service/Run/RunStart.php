<?php

declare(strict_types=1);

namespace App\Service\Run;

/**
 * The answer to a start: the run's id, and whether this call opened it or a
 * retry with the same key found the run an earlier call opened.
 */
final readonly class RunStart
{
    public function __construct(
        public string $runId,
        public bool $opened,
    ) {
    }
}
