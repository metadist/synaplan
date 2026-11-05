<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Calendar;

use App\Service\Calendar\UserNamedClockTime;
use PHPUnit\Framework\TestCase;

final class UserNamedClockTimeTest extends TestCase
{
    public function testGermanDateWithoutHourIsNotAClockTime(): void
    {
        self::assertFalse(UserNamedClockTime::in(
            'stelle mir einen termin für eine bahnfahrt morgen in meinen kalender',
        ));
    }

    public function testNamedHoursCount(): void
    {
        self::assertTrue(UserNamedClockTime::in('Dentist tomorrow at 10'));
        self::assertTrue(UserNamedClockTime::in('termin morgen um 15 Uhr'));
        self::assertTrue(UserNamedClockTime::in('meeting at 9:00'));
        self::assertTrue(UserNamedClockTime::in('call at 3pm'));
        self::assertTrue(UserNamedClockTime::in('in 2 hours'));
    }
}
