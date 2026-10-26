<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

/**
 * Makes two writings of the same words compare equal: quotes, line-break
 * hyphens, and the two ways of writing a number (12,40 and 12.40, 1.234,5
 * and 1,234.5).
 */
final class PassageText
{
    public static function normalise(string $text): string
    {
        $text = mb_strtolower($text);
        $text = str_replace(["\u{201C}", "\u{201D}", "\u{201E}", "\u{00AB}", "\u{00BB}", '"', "'"], '', $text);
        $text = preg_replace('/(\p{L})-\s+(?=\p{L})/u', '$1', $text) ?? $text;
        $text = preg_replace('/(\p{L})-(?=\p{L})/u', '$1', $text) ?? $text;
        $text = self::canonicalNumbers($text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    public static function canonicalNumbers(string $text): string
    {
        return preg_replace_callback(
            '/\d{1,3}(?:[.,\s\x{00A0}\x{202F}]\d{3})+(?:[.,]\d+)?|\d+(?:[.,]\d+)?/u',
            static fn (array $match): string => self::oneNumber($match[0]),
            $text,
        ) ?? $text;
    }

    /**
     * Dates written as 2024-03-12, 12.03.2024 or 12 March 2024, as ISO.
     * A dotted date is day.month.year. A regulation number such as
     * 2024/1689 is not a date.
     *
     * @return list<string>
     */
    public static function datesIn(string $text): array
    {
        $found = [];
        $lower = mb_strtolower($text);
        if (preg_match_all('/\b(\d{4})-(\d{2})-(\d{2})\b/u', $lower, $rows, PREG_SET_ORDER) > 0) {
            foreach ($rows as $row) {
                self::keepDate($found, (int) $row[1], (int) $row[2], (int) $row[3]);
            }
        }
        if (preg_match_all('/\b(\d{1,2})\.(\d{1,2})\.(\d{4})\b/u', $lower, $rows, PREG_SET_ORDER) > 0) {
            foreach ($rows as $row) {
                self::keepDate($found, (int) $row[3], (int) $row[2], (int) $row[1]);
            }
        }
        $months = self::monthPattern();
        if (preg_match_all('/\b(\d{1,2})\.?\s+('.$months.')\.?\s+(\d{4})\b/u', $lower, $rows, PREG_SET_ORDER) > 0) {
            foreach ($rows as $row) {
                self::keepDate($found, (int) $row[3], self::MONTHS[$row[2]], (int) $row[1]);
            }
        }
        if (preg_match_all('/\b('.$months.')\.?\s+(\d{1,2}),?\s+(\d{4})\b/u', $lower, $rows, PREG_SET_ORDER) > 0) {
            foreach ($rows as $row) {
                self::keepDate($found, (int) $row[3], self::MONTHS[$row[1]], (int) $row[2]);
            }
        }
        $found = array_values(array_unique($found));
        sort($found);

        return $found;
    }

    /** The same text with its dates removed, so a date is not also a number. */
    public static function withoutDates(string $text): string
    {
        $months = self::monthPattern();
        $text = preg_replace('/\b\d{4}-\d{2}-\d{2}\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d{1,2}\.\d{1,2}\.\d{4}\b/u', ' ', $text) ?? $text;
        $text = preg_replace('/\b\d{1,2}\.?\s+(?:'.$months.')\.?\s+\d{4}\b/ui', ' ', $text) ?? $text;
        $text = preg_replace('/\b(?:'.$months.')\.?\s+\d{1,2},?\s+\d{4}\b/ui', ' ', $text) ?? $text;

        return $text;
    }

    /** A month name or its short form, in any of the five languages of the app. */
    public static function isMonth(string $word): bool
    {
        return isset(self::MONTHS[mb_strtolower($word)]);
    }

    /**
     * @return list<string>
     */
    public static function numbersIn(string $text): array
    {
        $normalised = self::canonicalNumbers($text);
        preg_match_all('/\d+(?:\.\d+)?/u', $normalised, $matches);

        return $matches[0];
    }

    /** @var array<string, int> */
    private const MONTHS = [
        'january' => 1, 'jan' => 1, 'february' => 2, 'feb' => 2, 'march' => 3, 'mar' => 3,
        'april' => 4, 'apr' => 4, 'may' => 5, 'june' => 6, 'jun' => 6, 'july' => 7, 'jul' => 7,
        'august' => 8, 'aug' => 8, 'september' => 9, 'sep' => 9, 'october' => 10, 'oct' => 10,
        'november' => 11, 'nov' => 11, 'december' => 12, 'dec' => 12,
        'januar' => 1, 'februar' => 2, 'märz' => 3, 'maerz' => 3, 'mai' => 5, 'juni' => 6,
        'juli' => 7, 'oktober' => 10, 'dezember' => 12,
        'janvier' => 1, 'février' => 2, 'fevrier' => 2, 'mars' => 3, 'avril' => 4, 'juin' => 6,
        'juillet' => 7, 'août' => 8, 'aout' => 8, 'septembre' => 9, 'octobre' => 10,
        'novembre' => 11, 'décembre' => 12, 'decembre' => 12,
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7,
        'agosto' => 8, 'septiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'ocak' => 1, 'şubat' => 2, 'subat' => 2, 'mart' => 3, 'nisan' => 4, 'mayıs' => 5,
        'mayis' => 5, 'haziran' => 6, 'temmuz' => 7, 'ağustos' => 8, 'agustos' => 8,
        'eylül' => 9, 'eylul' => 9, 'ekim' => 10, 'kasım' => 11, 'kasim' => 11,
        'aralık' => 12, 'aralik' => 12,
    ];

    private static function monthPattern(): string
    {
        $names = array_keys(self::MONTHS);
        usort($names, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

        return implode('|', $names);
    }

    /**
     * @param list<string> $found
     */
    private static function keepDate(array &$found, int $year, int $month, int $day): void
    {
        if ($year < 1000 || !checkdate($month, $day, $year)) {
            return;
        }
        $found[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private static function oneNumber(string $raw): string
    {
        $raw = str_replace([' ', "\u{00A0}", "\u{202F}"], '', $raw);
        $lastDot = strrpos($raw, '.');
        $lastComma = strrpos($raw, ',');
        if (false !== $lastDot && false !== $lastComma) {
            $decimalIsDot = $lastDot > $lastComma;
            $raw = str_replace($decimalIsDot ? ',' : '.', '', $raw);

            return str_replace($decimalIsDot ? '.' : ',', '.', $raw);
        }

        $separator = false !== $lastDot ? '.' : (false !== $lastComma ? ',' : '');
        if ('' === $separator) {
            return $raw;
        }
        $quoted = preg_quote($separator, '/');
        if (1 === preg_match('/^\d{1,3}(?:'.$quoted.'\d{3})+$/', $raw)) {
            return str_replace($separator, '', $raw);
        }

        return str_replace($separator, '.', $raw);
    }
}
