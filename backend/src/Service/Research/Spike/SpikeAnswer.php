<?php

declare(strict_types=1);

namespace App\Service\Research\Spike;

final readonly class SpikeAnswer
{
    public function __construct(
        public string $report,
        public int $searches,
        public int $reads,
        public int $keptFindings,
        public int $droppedFindings,
        public int $hits,
        public string $readLog,
        public int $inputTokens,
        public int $outputTokens,
    ) {
    }
}
