<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Whether a scheduler slot should run now, and otherwise how long to wait.
 */
final readonly class SchedulerSlotDecision
{
    public function __construct(
        public bool $due,
        public int $secondsUntilDue,
    ) {
        if ($this->due && 0 !== $this->secondsUntilDue) {
            throw new \InvalidArgumentException('A due scheduler slot does not have a wait.');
        }
        if (!$this->due && $this->secondsUntilDue < 1) {
            throw new \InvalidArgumentException('Seconds until a scheduler slot is due must be at least 1.');
        }
    }

    public static function due(): self
    {
        return new self(true, 0);
    }

    public static function waiting(int $seconds): self
    {
        return new self(false, max(1, $seconds));
    }
}
