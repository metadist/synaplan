<?php

declare(strict_types=1);

namespace App\Service\Research\Run\Ledger;

/**
 * One line of text a person or a model may read. Control characters are
 * removed so a page title cannot break out of its line.
 */
final class PlainText
{
    public static function line(string $text, int $max = 400): string
    {
        $clean = preg_replace('/\p{C}+/u', ' ', $text) ?? $text;
        $clean = preg_replace('/\s+/u', ' ', $clean) ?? $clean;
        $clean = trim($clean);
        if (mb_strlen($clean) <= $max) {
            return $clean;
        }

        return rtrim(mb_substr($clean, 0, max(1, $max - 1))).'…';
    }
}
