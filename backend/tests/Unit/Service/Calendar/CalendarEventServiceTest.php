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
}
