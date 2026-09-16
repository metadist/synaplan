<?php

declare(strict_types=1);

namespace App\AI\Health;

/**
 * Outcome counts for one model inside the current rolling window.
 */
final readonly class ModelHealthCounters
{
    public function __construct(
        public int $successes = 0,
        public int $failures = 0,
        public ?FailureKind $lastKind = null,
        public ?string $lastMessage = null,
        public int $lastFailureAt = 0,
        public int $lastSuccessAt = 0,
    ) {
    }

    public function total(): int
    {
        return $this->successes + $this->failures;
    }

    /** Failure share in percent, 0 when nothing was recorded. */
    public function errorRatePercent(): int
    {
        $total = $this->total();

        return 0 === $total ? 0 : (int) round($this->failures * 100 / $total);
    }

    public function isEmpty(): bool
    {
        return 0 === $this->total();
    }
}
