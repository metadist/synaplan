<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Service\Calendar\ClockTimeEvidence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An entry taken from a document is written only when the document names its
 * time. The check is for one hour and minute, so a booking that says 14:30
 * never backs a 09:00 the model made up.
 */
final class ClockTimeEvidenceTest extends TestCase
{
    /**
     * Text of a flight booking in the shape a picture's text extraction
     * returns. The booking is invented.
     */
    private const BOOKING = "Zusammenfassung Ihrer Reise\n"
        ."Hinreise • Freitag 12 März, 2027\n06:45 1h 35min 08:20\nHamburg Flughafen (HAM) Wien (VIE)\n"
        ."Hinreise • Samstag 13 März, 2027\n19:30 3h 45min 22:15\nWien (VIE) Lissabon (LIS)\n"
        ."Hinreise • Mittwoch 17 März, 2027\n14:00 3h 0min 17:00\nLissabon (LIS) Hamburg Flughafen (HAM)\n"
        .'Summe 1.842,60 EUR';

    #[DataProvider('bookingTimes')]
    public function testTheBookingNamesItsOwnTimesAndNoOthers(string $time, bool $named): void
    {
        self::assertSame($named, ClockTimeEvidence::names(self::at($time), self::BOOKING));
    }

    /**
     * @return iterable<string, array{0: string, 1: bool}>
     */
    public static function bookingTimes(): iterable
    {
        foreach (['06:45', '08:20', '19:30', '22:15', '14:00', '17:00'] as $time) {
            yield 'on the booking: '.$time => [$time, true];
        }
        yield 'the hour a planner likes to invent' => ['09:00', false];
        yield 'digits of the price' => ['18:42', false];
        yield 'day and month of a date' => ['12:03', false];
        yield 'the length of a flight' => ['03:00', false];
    }

    #[DataProvider('writtenForms')]
    public function testCommonWrittenForms(string $time, string $text, bool $named): void
    {
        self::assertSame($named, ClockTimeEvidence::names(self::at($time), $text));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function writtenForms(): iterable
    {
        yield 'German whole hour' => ['15:00', 'Termin um 15 Uhr', true];
        yield 'German bare hour after um' => ['09:00', 'morgen um 9', true];
        yield 'um 9:30 is not nine sharp' => ['09:00', 'morgen um 9:30', false];
        yield 'German dotted time' => ['06:45', 'Abflug 6.45 Uhr', true];
        yield 'a dotted price is not a time' => ['06:45', 'Preis 6.45 EUR', false];
        yield 'French h form' => ['14:30', 'rendez-vous à 14h30', true];
        yield 'Turkish saat' => ['15:00', 'saat 15', true];
        yield 'English pm' => ['15:00', 'at 3 pm', true];
        yield 'English pm without a space' => ['15:00', 'at 3pm sharp', true];
        yield 'pm is not the morning' => ['03:00', 'at 3 pm', false];
        yield 'English minutes with pm' => ['15:30', 'starts 3:30 PM', true];
        yield 'minutes with pm are not the morning' => ['03:30', 'starts 3:30 PM', false];
        yield 'a bare English hour is the morning' => ['10:00', 'tomorrow at 10', true];
        yield 'a bare English hour is not the evening' => ['22:00', 'tomorrow at 10', false];
        yield 'noon' => ['12:00', 'lunch at noon', true];
        yield 'Mitternacht' => ['00:00', 'um Mitternacht', true];
        yield 'ISO date-time' => ['06:45', 'start 2027-03-12T06:45:00', true];
        yield 'part of a later hour' => ['06:45', 'Abflug 16:45', false];
        yield 'a different hour' => ['16:45', 'Abflug 06:45', false];
    }

    public function testEmptyTextNamesNothing(): void
    {
        self::assertFalse(ClockTimeEvidence::names(self::at('06:45'), "  \n"));
    }

    private static function at(string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2027-03-12 '.$time, new \DateTimeZone('Europe/Berlin'));
    }
}
