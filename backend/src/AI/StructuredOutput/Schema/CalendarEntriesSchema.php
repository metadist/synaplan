<?php

declare(strict_types=1);

namespace App\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * JSON schema for {@see \App\Service\Calendar\CalendarEntryExtractor}: the
 * calendar entries found in text that was read (a booking, an invitation).
 *
 * The list is wrapped under `entries` because structured output requires an
 * object root. Optional fields are nullable and still listed in `required`,
 * which strict mode demands.
 */
final class CalendarEntriesSchema
{
    public static function build(): StructuredOutputSchema
    {
        return new StructuredOutputSchema(
            name: 'calendar_entries',
            schema: [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'entries' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'start' => ['type' => 'string'],
                                'end' => ['type' => ['string', 'null']],
                                'location' => ['type' => ['string', 'null']],
                                'description' => ['type' => ['string', 'null']],
                            ],
                            'required' => ['title', 'start', 'end', 'location', 'description'],
                        ],
                    ],
                ],
                'required' => ['entries'],
            ],
        );
    }
}
