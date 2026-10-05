<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * The BCONFIG row for one scheduler slot, as read before a compare-and-set.
 */
final readonly class SchedulerSlotSnapshot
{
    public function __construct(
        public bool $exists,
        public ?int $lastStart,
        public ?string $rawValue,
    ) {
    }
}
