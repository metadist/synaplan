<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * Whether the user named a clock time (an hour of day), not only a date.
 *
 * "tomorrow" / "Freitag" / "morgen" are dates. Without an hour the calendar
 * runner must ask instead of inventing 09:00.
 */
final class UserNamedClockTime
{
    public static function in(string $messageText): bool
    {
        $text = trim($messageText);
        if ('' === $text) {
            return false;
        }

        return 1 === preg_match(
            '/(?:'
            .'\b(?:[01]?\d|2[0-3])[:.][0-5]\d\b'
            .'|\b(?:[1-9]|1[0-2])\s*(?:am|pm)\b'
            .'|\b(?:[01]?\d|2[0-3])\s*h\b'
            .'|\b(?:[01]?\d|2[0-3])\s*uhr\b'
            .'|\bum\s+(?:[01]?\d|2[0-3])\b'
            .'|\bat\s+(?:[01]?\d|2[0-3])\b'
            .'|\bà\s+(?:[01]?\d|2[0-3])\b'
            .'|\bin\s+\d+\s*(?:hours?|stunden?|min(?:ute)?s?|minuten)\b'
            .'|\b(?:noon|midnight|mittags?|mitternacht)\b'
            .')/iu',
            $text,
        );
    }
}
