<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Service\Calendar\CalendarEventService;
use PHPUnit\Framework\TestCase;

final class CalendarEventServiceTest extends TestCase
{
    private CalendarEventService $service;

    protected function setUp(): void
    {
        $this->service = new CalendarEventService();
    }

    public function testProducesValidVcalendarSkeleton(): void
    {
        $ics = $this->service->buildIcs(
            'Sync',
            new \DateTimeImmutable('2026-06-09T15:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-09T16:00:00', new \DateTimeZone('UTC')),
        );

        self::assertStringContainsString("BEGIN:VCALENDAR\r\n", $ics);
        self::assertStringContainsString('VERSION:2.0', $ics);
        self::assertStringContainsString('BEGIN:VEVENT', $ics);
        self::assertStringContainsString('END:VEVENT', $ics);
        self::assertStringContainsString("END:VCALENDAR\r\n", $ics);
        self::assertStringContainsString('UID:', $ics);
        self::assertStringContainsString('DTSTAMP:', $ics);
        self::assertStringContainsString('SUMMARY:Sync', $ics);
        // RFC 5545 requires CRLF line breaks.
        self::assertStringContainsString("\r\n", $ics);
    }

    public function testConvertsLocalTimeToUtcInstant(): void
    {
        // 15:00 Berlin in June (CEST, UTC+2) == 13:00 UTC.
        $ics = $this->service->buildIcs(
            'Meeting',
            new \DateTimeImmutable('2026-06-09T15:00:00', new \DateTimeZone('Europe/Berlin')),
            new \DateTimeImmutable('2026-06-09T16:00:00', new \DateTimeZone('Europe/Berlin')),
            timezoneLabel: 'Europe/Berlin',
        );

        self::assertStringContainsString('DTSTART:20260609T130000Z', $ics);
        self::assertStringContainsString('DTEND:20260609T140000Z', $ics);
        self::assertStringContainsString('Time zone: Europe/Berlin', $ics);
    }

    public function testGuaranteesPositiveDurationWhenEndNotAfterStart(): void
    {
        $start = new \DateTimeImmutable('2026-06-09T15:00:00', new \DateTimeZone('UTC'));
        $ics = $this->service->buildIcs('X', $start, $start);

        self::assertStringContainsString('DTSTART:20260609T150000Z', $ics);
        // Falls back to a 1-hour event.
        self::assertStringContainsString('DTEND:20260609T160000Z', $ics);
    }

    public function testEscapesTextValues(): void
    {
        $ics = $this->service->buildIcs(
            'Lunch, with; team',
            new \DateTimeImmutable('2026-06-09T12:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-09T13:00:00', new \DateTimeZone('UTC')),
            description: "line1\nline2",
        );

        self::assertStringContainsString('SUMMARY:Lunch\\, with\\; team', $ics);
        self::assertStringContainsString('DESCRIPTION:line1\\nline2', $ics);
    }

    public function testEmailAttendeesBecomeAttendeeRowsAndNamesGoToDescription(): void
    {
        $ics = $this->service->buildIcs(
            'Review',
            new \DateTimeImmutable('2026-06-09T09:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-09T09:30:00', new \DateTimeZone('UTC')),
            attendees: ['tom@example.com', 'Sarah'],
        );

        self::assertStringContainsString('ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:tom@example.com', $ics);
        self::assertStringContainsString('Attendees: Sarah', $ics);
        self::assertStringNotContainsString('mailto:Sarah', $ics);
    }

    public function testOrganizerOnlyEmittedForValidEmail(): void
    {
        $start = new \DateTimeImmutable('2026-06-09T09:00:00', new \DateTimeZone('UTC'));
        $end = new \DateTimeImmutable('2026-06-09T10:00:00', new \DateTimeZone('UTC'));

        $withOrganizer = $this->service->buildIcs('A', $start, $end, organizerEmail: 'boss@example.com');
        self::assertStringContainsString('ORGANIZER:mailto:boss@example.com', $withOrganizer);

        $withoutOrganizer = $this->service->buildIcs('A', $start, $end, organizerEmail: 'not-an-email');
        self::assertStringNotContainsString('ORGANIZER', $withoutOrganizer);
    }

    public function testLongLinesAreFoldedTo75Octets(): void
    {
        $ics = $this->service->buildIcs(
            str_repeat('A very long meeting title ', 10),
            new \DateTimeImmutable('2026-06-09T09:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-09T10:00:00', new \DateTimeZone('UTC')),
        );

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, strlen($line), 'Each content line must be <= 75 octets');
        }
    }

    /**
     * The flights of one booking travel in one file: one calendar wrapper,
     * one event block per flight, each with its own id. A connected calendar
     * reads the blocks and creates one event for each.
     */
    public function testSeveralEntriesShareOneCalendarFile(): void
    {
        $zone = new \DateTimeZone('Europe/Berlin');
        $ics = $this->service->buildIcsForEntries([
            [
                'title' => 'Flug Hamburg (HAM) → Wien (VIE)',
                'start' => new \DateTimeImmutable('2027-03-12T06:45:00', $zone),
                'end' => new \DateTimeImmutable('2027-03-12T08:20:00', $zone),
                'location' => 'Hamburg Flughafen (HAM)',
                'description' => 'Buchungsnummer 4471-205-918',
            ],
            [
                'title' => 'Flug Wien (VIE) → Lissabon (LIS)',
                'start' => new \DateTimeImmutable('2027-03-13T19:30:00', $zone),
                'end' => new \DateTimeImmutable('2027-03-13T22:15:00', $zone),
            ],
            [
                'title' => 'Flug Lissabon (LIS) → Hamburg (HAM)',
                'start' => new \DateTimeImmutable('2027-03-17T14:00:00', $zone),
                'end' => new \DateTimeImmutable('2027-03-17T17:00:00', $zone),
            ],
        ], 'Europe/Berlin');

        self::assertSame(1, substr_count($ics, 'BEGIN:VCALENDAR'));
        self::assertSame(1, substr_count($ics, 'END:VCALENDAR'));
        self::assertSame(3, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertSame(3, substr_count($ics, 'END:VEVENT'));
        self::assertStringEndsWith("END:VEVENT\r\nEND:VCALENDAR\r\n", $ics);

        // Mid-March is CET (UTC+1).
        self::assertStringContainsString('DTSTART:20270312T054500Z', $ics);
        self::assertStringContainsString('DTEND:20270312T072000Z', $ics);
        self::assertStringContainsString('DTSTART:20270313T183000Z', $ics);
        self::assertStringContainsString('DTSTART:20270317T130000Z', $ics);
        self::assertStringContainsString('LOCATION:Hamburg Flughafen (HAM)', $ics);
        self::assertStringContainsString('Buchungsnummer 4471-205-918', $ics);

        preg_match_all('/^UID:(.+)$/m', $ics, $uids);
        self::assertCount(3, array_unique($uids[1]));
    }

    public function testASingleEventKeepsItsShape(): void
    {
        $ics = $this->service->buildIcs(
            'Sync',
            new \DateTimeImmutable('2026-06-09T15:00:00', new \DateTimeZone('UTC')),
            new \DateTimeImmutable('2026-06-09T16:00:00', new \DateTimeZone('UTC')),
        );

        $lines = explode("\r\n", rtrim($ics));
        self::assertSame(['BEGIN:VCALENDAR', 'VERSION:2.0'], array_slice($lines, 0, 2));
        self::assertSame('CALSCALE:GREGORIAN', $lines[3]);
        self::assertSame('METHOD:PUBLISH', $lines[4]);
        self::assertSame('BEGIN:VEVENT', $lines[5]);
        self::assertSame(['STATUS:CONFIRMED', 'END:VEVENT', 'END:VCALENDAR'], array_slice($lines, -3));
    }
}
