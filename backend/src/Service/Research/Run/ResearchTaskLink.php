<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

/** The saved-task step that is waiting for this research. */
final readonly class ResearchTaskLink
{
    public function __construct(
        public int $runId,
        public string $nodeId,
    ) {
    }
}
