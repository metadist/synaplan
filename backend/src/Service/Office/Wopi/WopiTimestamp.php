<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

/**
 * Collabora compares the PutFile timestamp with the CheckFileInfo value as a
 * string. The format is the one CODE sends back: ISO-8601 UTC with seven
 * fractional digits. A header that parses to the same second also matches,
 * so a client that drops trailing zeros is not a conflict.
 */
final class WopiTimestamp
{
    public static function format(int $unix): string
    {
        return gmdate('Y-m-d\TH:i:s', $unix).'.0000000Z';
    }

    public static function matches(string $header, int $unix): bool
    {
        $header = trim($header);
        if ('' === $header) {
            return false;
        }
        if (hash_equals(self::format($unix), $header)) {
            return true;
        }
        $parsed = strtotime($header);

        return false !== $parsed && $parsed === $unix;
    }
}
