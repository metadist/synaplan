<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * Builds RFC 5545 iCalendar (.ics) content for a single meeting/event.
 *
 * Times are emitted as UTC instants (DTSTART/DTEND with a trailing "Z"), which
 * every calendar client converts back to the viewer's local time — so the
 * absolute moment is always correct regardless of the server timezone. The
 * originating IANA timezone is also recorded in the description for clarity.
 *
 * No external dependency: the ICS is assembled by hand with correct value
 * escaping, 75-octet line folding and CRLF line endings.
 */
final class CalendarEventService
{
    private const PRODID = '-//Synaplan//Calendar 1.0//EN';

    /**
     * @param list<string> $attendees attendee labels; entries that are valid
     *                                email addresses become ATTENDEE rows, the
     *                                rest are listed in the description
     */
    public function buildIcs(
        string $title,
        \DateTimeImmutable $start,
        \DateTimeImmutable $end,
        ?string $description = null,
        ?string $location = null,
        array $attendees = [],
        ?string $organizerEmail = null,
        ?string $timezoneLabel = null,
    ): string {
        $utc = new \DateTimeZone('UTC');
        $startUtc = $start->setTimezone($utc);
        $endUtc = $end->setTimezone($utc);
        if ($endUtc <= $startUtc) {
            // Guarantee a positive duration (default 60 minutes).
            $endUtc = $startUtc->add(new \DateInterval('PT1H'));
        }

        $uid = bin2hex(random_bytes(16)).'@synaplan';
        $stamp = (new \DateTimeImmutable('now', $utc))->format('Ymd\THis\Z');

        $emailAttendees = [];
        $nameAttendees = [];
        foreach ($attendees as $attendee) {
            $attendee = trim($attendee);
            if ('' === $attendee) {
                continue;
            }
            if (filter_var($attendee, \FILTER_VALIDATE_EMAIL)) {
                $emailAttendees[] = $attendee;
            } else {
                $nameAttendees[] = $attendee;
            }
        }

        $descriptionParts = [];
        if (null !== $description && '' !== trim($description)) {
            $descriptionParts[] = trim($description);
        }
        if ([] !== $nameAttendees) {
            $descriptionParts[] = 'Attendees: '.implode(', ', $nameAttendees);
        }
        if (null !== $timezoneLabel && '' !== trim($timezoneLabel)) {
            $descriptionParts[] = 'Time zone: '.trim($timezoneLabel);
        }
        $descriptionText = implode("\n", $descriptionParts);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.$this->escapeText(self::PRODID),
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$stamp,
            'DTSTART:'.$startUtc->format('Ymd\THis\Z'),
            'DTEND:'.$endUtc->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escapeText('' !== trim($title) ? trim($title) : 'Meeting'),
        ];

        if ('' !== $descriptionText) {
            $lines[] = 'DESCRIPTION:'.$this->escapeText($descriptionText);
        }
        if (null !== $location && '' !== trim($location)) {
            $lines[] = 'LOCATION:'.$this->escapeText(trim($location));
        }
        if (null !== $organizerEmail && filter_var($organizerEmail, \FILTER_VALIDATE_EMAIL)) {
            $lines[] = 'ORGANIZER:mailto:'.$organizerEmail;
        }
        foreach ($emailAttendees as $email) {
            $lines[] = 'ATTENDEE;ROLE=REQ-PARTICIPANT;RSVP=TRUE:mailto:'.$email;
        }

        $lines[] = 'STATUS:CONFIRMED';
        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map([$this, 'foldLine'], $lines))."\r\n";
    }

    /**
     * Escape a TEXT value per RFC 5545 §3.3.11 (backslash, semicolon, comma,
     * newline).
     */
    private function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(["\r\n", "\r", "\n"], '\\n', $value);
        $value = str_replace(';', '\\;', $value);

        return str_replace(',', '\\,', $value);
    }

    /**
     * Fold a content line to <=75 octets per RFC 5545 §3.1 (continuation lines
     * start with a single space). Splitting is done on whole UTF-8 characters so
     * multi-byte sequences are never cut.
     */
    private function foldLine(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }

        $chunks = [];
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current.$char) > 74) {
                $chunks[] = $current;
                $current = $char;
            } else {
                $current .= $char;
            }
        }
        $chunks[] = $current;

        return implode("\r\n ", $chunks);
    }
}
