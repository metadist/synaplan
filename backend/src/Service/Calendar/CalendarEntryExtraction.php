<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * What {@see CalendarEntryExtractor} found, and what the model call cost.
 */
final readonly class CalendarEntryExtraction
{
    /**
     * @param list<CalendarEntry>  $entries  entries whose time the read text names
     * @param int                  $rejected entries the model proposed with a time the text does not name
     * @param array<string, mixed> $usage    provider, model, model id and token usage of the call, in the
     *                                       keys a step result carries
     */
    public function __construct(
        public array $entries,
        public int $rejected = 0,
        public array $usage = [],
    ) {
    }
}
