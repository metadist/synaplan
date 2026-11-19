<?php

declare(strict_types=1);

namespace App\Service\Calendar;

/**
 * Whether a text names one clock time: this hour and this minute.
 *
 * {@see UserNamedClockTime} asks whether a message names any hour at all.
 * That is enough for the person's own words. For a picture, a document or an
 * earlier step it is not: almost every document contains a number that looks
 * like a time. An entry taken from what was read is therefore only written
 * when that text names the entry's own time, so 09:00 is never entered next
 * to a booking that says 14:30.
 */
final class ClockTimeEvidence
{
    private const NOON = '/\b(?:noon|midday|mittags?|midi|mediod[ií]a)\b/iu';

    private const MIDNIGHT = '/\b(?:midnight|mitternacht|minuit|medianoche)\b/iu';

    /** A meridiem after a time means the 12-hour patterns decide, not the 24-hour ones. */
    private const NO_MERIDIEM = '(?!\s*(?:a\.?\s?m|p\.?\s?m)\b)';

    public static function names(\DateTimeImmutable $time, string $text): bool
    {
        if ('' === trim($text)) {
            return false;
        }

        foreach (self::patterns((int) $time->format('G'), $time->format('i')) as $pattern) {
            if (1 === preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function patterns(int $hour, string $minute): array
    {
        $h = '0?'.$hour;
        $h12 = '0?'.(0 === $hour % 12 ? 12 : $hour % 12);
        $meridiem = $hour < 12 ? 'a\.?\s?m\.?' : 'p\.?\s?m\.?';
        // A digit, a colon or a dot in front makes it part of another number (16:45, 12.03.).
        $start = '(?<![\d:.])';

        $patterns = [
            '/'.$start.$h.':'.$minute.'(?!\d)'.self::NO_MERIDIEM.'/iu',
            '/'.$start.$h.'\.'.$minute.'\s*(?:uhr|h)\b/iu',
            '/'.$start.$h.'\s?h\s?'.$minute.'(?!\d)/iu',
            '/'.$start.$h12.'[:.]'.$minute.'\s*'.$meridiem.'(?![a-z])/iu',
        ];

        if ('00' !== $minute) {
            return $patterns;
        }

        $patterns[] = '/'.$start.$h.'\s*uhr\b/iu';
        $patterns[] = '/'.$start.$h.'\s*h\b(?!\s?\d)/iu';
        $patterns[] = '/\b(?:um|at|à|a\s+las|saat)\s+'.$h.'\b(?![:.]\d)'.self::NO_MERIDIEM.'/iu';
        $patterns[] = '/'.$start.$h12.'\s*'.$meridiem.'(?![a-z])/iu';
        if (12 === $hour) {
            $patterns[] = self::NOON;
        }
        if (0 === $hour) {
            $patterns[] = self::MIDNIGHT;
        }

        return $patterns;
    }
}
