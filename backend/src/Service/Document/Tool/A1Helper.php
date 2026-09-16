<?php

declare(strict_types=1);

namespace App\Service\Document\Tool;

/**
 * A1 address helpers for spreadsheet tools.
 */
final class A1Helper
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function expandRange(string $range): array
    {
        $range = strtoupper(str_replace('$', '', trim($range)));
        if ('' === $range) {
            return [];
        }
        if (!str_contains($range, ':')) {
            return preg_match('/^[A-Z]+[0-9]+$/', $range) ? [$range] : [];
        }
        [$start, $end] = explode(':', $range, 2);
        if (!preg_match('/^([A-Z]+)([0-9]+)$/', $start, $a) || !preg_match('/^([A-Z]+)([0-9]+)$/', $end, $b)) {
            return [];
        }
        $c1 = self::columnIndex($a[1]);
        $c2 = self::columnIndex($b[1]);
        $r1 = (int) $a[2];
        $r2 = (int) $b[2];
        $out = [];
        for ($r = min($r1, $r2); $r <= max($r1, $r2); ++$r) {
            for ($c = min($c1, $c2); $c <= max($c1, $c2); ++$c) {
                $out[] = self::columnLetter($c).$r;
            }
        }

        return $out;
    }

    public static function columnIndex(string $letters): int
    {
        $letters = strtoupper($letters);
        $n = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; ++$i) {
            $n = $n * 26 + (ord($letters[$i]) - 64);
        }

        return $n;
    }

    public static function columnLetter(int $index): string
    {
        $index = max(1, $index);
        $s = '';
        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $s = chr(65 + $mod).$s;
            $index = intdiv($index - 1, 26);
        }

        return $s;
    }

    /**
     * @return array{0: string, 1: int}|null
     */
    public static function split(string $address): ?array
    {
        if (!preg_match('/^([A-Z]+)([0-9]+)$/', strtoupper($address), $m)) {
            return null;
        }

        return [$m[1], (int) $m[2]];
    }
}
