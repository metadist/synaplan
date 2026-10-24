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
     * @return list<string>
     */
    public static function numbersIn(string $text): array
    {
        $normalised = self::canonicalNumbers($text);
        preg_match_all('/\d+(?:\.\d+)?/u', $normalised, $matches);

        return $matches[0];
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
