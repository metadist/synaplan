<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * One calendar entry taken from text that was read: a flight of a booking,
 * a meeting of an invitation. The times are wall-clock times as written in
 * that text ("2027-03-12T06:45:00"), without a zone of their own.
 */
final readonly class CalendarEntry
{
    public function __construct(
        public string $title,
        public string $start,
        public ?string $end = null,
        public ?string $location = null,
        public ?string $description = null,
    ) {
    }
}
