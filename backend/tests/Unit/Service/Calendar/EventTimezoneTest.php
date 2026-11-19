<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Service\Calendar\EventTimezone;
use PHPUnit\Framework\TestCase;

/**
 * The zone rules of a calendar event, without a runner around them. The
 * runner-level cases are in CalendarTimezoneTest.
 */
final class EventTimezoneTest extends TestCase
{
    public function testAZoneThePersonNamedWinsOverProfileAndPlanner(): void
    {
        $resolved = EventTimezone::resolve('Europe/Berlin', 'Deploy review tomorrow at 10:00 UTC', 'Europe/Berlin');

        self::assertNotNull($resolved);
        self::assertSame('UTC', $resolved[1]);
        self::assertFalse($resolved[2]);
    }

    public function testTheProfileZoneBeatsAPlannerGuessAndReinterpretsOffsets(): void
    {
        $resolved = EventTimezone::resolve('UTC', 'Dentist tomorrow at 10', 'Europe/Berlin');

        self::assertNotNull($resolved);
        self::assertSame('Europe/Berlin', $resolved[1]);
        self::assertTrue($resolved[2], 'a planner "Z" is read as wall clock in the profile zone');
    }

    public function testAPlannerZoneNamedByTheMessageWinsOverTheProfile(): void
    {
        $resolved = EventTimezone::resolve('America/New_York', 'Call with New York office tomorrow at 10', 'Europe/Berlin');

        self::assertNotNull($resolved);
        self::assertSame('America/New_York', $resolved[1]);
    }

    public function testWithoutAProfileAPlannerZoneCountsUnlessItIsUtc(): void
    {
        self::assertSame('Europe/Berlin', EventTimezone::resolve('Europe/Berlin', 'Dentist tomorrow at 10', null)[1] ?? null);
        self::assertNull(EventTimezone::resolve('UTC', 'Dentist tomorrow at 10', null));
        self::assertNull(EventTimezone::resolve('', 'Dentist tomorrow at 10', null));
        self::assertNull(EventTimezone::resolve('Mars/Olympus', 'Dentist tomorrow at 10', null));
    }

    public function testValidReturnsTheCanonicalNameOrNull(): void
    {
        self::assertSame('Europe/Berlin', EventTimezone::valid(' europe/berlin '));
        self::assertSame('UTC', EventTimezone::valid('UTC'));
        self::assertNull(EventTimezone::valid(''));
        self::assertNull(EventTimezone::valid('Mars/Olympus'));
    }

    public function testParsePlacesAWallClockInTheZone(): void
    {
        $berlin = new \DateTimeZone('Europe/Berlin');

        // Offset kept: 10:00Z stays 10:00 UTC.
        self::assertSame('2026-09-19T10:00:00+00:00', EventTimezone::parse('2026-09-19T10:00:00Z', $berlin, false)->format(\DateTimeInterface::ATOM));
        // Offset cut off: the wall clock 10:00 is read in Berlin.
        self::assertSame('2026-09-19T10:00:00+02:00', EventTimezone::parse('2026-09-19T10:00:00Z', $berlin, true)->format(\DateTimeInterface::ATOM));
        self::assertSame('2027-03-12T06:45:00+01:00', EventTimezone::parse('2027-03-12T06:45:00', $berlin, true)->format(\DateTimeInterface::ATOM));
    }

    public function testParseRefusesAnEmptyValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        EventTimezone::parse('  ', new \DateTimeZone('UTC'), true);
    }
}
