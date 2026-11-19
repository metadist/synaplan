<?php

declare(strict_types=1);

namespace App\Service\Run;

use Psr\Clock\ClockInterface;

/**
 * Public run ids: ULIDs (`BRUNS.BUUID`). Sortable by start time, 80 random
 * bits, so an id cannot be guessed from another one.
 */
final class RunIds
{
    public const PATTERN = '/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/D';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function newId(ClockInterface $clock): string
    {
        $millis = (int) $clock->now()->format('Uv');
        if ($millis < 0) {
            throw new \InvalidArgumentException('A run id needs a time after 1970.');
        }

        $time = '';
        for ($i = 0; $i < 10; ++$i) {
            $time = self::ALPHABET[$millis % 32].$time;
            $millis = intdiv($millis, 32);
        }

        $random = '';
        foreach (str_split(random_bytes(10), 5) as $chunk) {
            $bits = '';
            foreach (str_split($chunk) as $byte) {
                $bits .= str_pad(decbin(\ord($byte)), 8, '0', \STR_PAD_LEFT);
            }
            foreach (str_split($bits, 5) as $group) {
                $random .= self::ALPHABET[(int) bindec($group)];
            }
        }

        return $time.$random;
    }

    public static function isValid(string $id): bool
    {
        return 1 === preg_match(self::PATTERN, $id);
    }
}
