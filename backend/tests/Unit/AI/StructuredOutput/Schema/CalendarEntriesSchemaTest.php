<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\StructuredOutput\Schema;

use App\AI\StructuredOutput\Schema\CalendarEntriesSchema;
use PHPUnit\Framework\TestCase;

final class CalendarEntriesSchemaTest extends TestCase
{
    public function testBuildNamesTheSchemaCalendarEntries(): void
    {
        self::assertSame('calendar_entries', CalendarEntriesSchema::build()->name);
    }

    public function testTheRootIsAnObjectThatWrapsTheList(): void
    {
        $schema = CalendarEntriesSchema::build()->schema;

        self::assertSame('object', $schema['type']);
        self::assertSame(['entries'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertSame('array', $schema['properties']['entries']['type']);
    }

    /**
     * Strict mode wants every property listed as required, so an optional
     * field is nullable instead of absent.
     */
    public function testOptionalFieldsAreNullableAndStillRequired(): void
    {
        $entry = CalendarEntriesSchema::build()->schema['properties']['entries']['items'];

        self::assertSame(['title', 'start', 'end', 'location', 'description'], $entry['required']);
        self::assertSame(array_keys($entry['properties']), $entry['required']);
        self::assertFalse($entry['additionalProperties']);
        self::assertSame(['type' => 'string'], $entry['properties']['title']);
        self::assertSame(['type' => 'string'], $entry['properties']['start']);
        foreach (['end', 'location', 'description'] as $optional) {
            self::assertSame(['type' => ['string', 'null']], $entry['properties'][$optional]);
        }
    }
}
