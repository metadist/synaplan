<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Lead;

use App\Service\Research\Run\Stage\StageResult;

/** What one tool call did. A stop ends the run before another tool runs. */
final readonly class LeadToolOutcome
{
    public function __construct(
        public string $json,
        public ?StageResult $stop = null,
    ) {
    }
}
