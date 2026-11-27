<?php

declare(strict_types=1);

namespace App\Service\Project\Item;

use App\Service\Project\ProjectException;

/**
 * The part of RFC 5545 RRULE a board date may carry: a frequency, an
 * interval, week days and an end by count or date. Anything else is
 * refused, so a stored rule is always one the board can say back
 * ("every Monday") and a calendar feed can pass on.
 */
final class RecurrenceRule
{
    public const MAX_LENGTH = 255;
    private const FREQUENCIES = ['DAILY', 'WEEKLY', 'MONTHLY', 'YEARLY'];
    private const MAX_INTERVAL = 99;
    private const MAX_COUNT = 999;
    private const DAY = '/^([+-]?[1-5])?(MO|TU|WE|TH|FR|SA|SU)$/';
    private const UNTIL = '/^\d{8}(T\d{6}Z)?$/';

    /**
     * @throws ProjectException when the rule is outside the supported part
     */
    public static function normalize(string $rule): string
    {
        $rule = strtoupper(trim($rule));
        if (str_starts_with($rule, 'RRULE:')) {
            $rule = substr($rule, 6);
        }
        if ('' === $rule || \strlen($rule) > self::MAX_LENGTH) {
            throw ProjectException::invalid('rrule is empty or too long');
        }

        $parts = [];
        foreach (explode(';', $rule) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            if ('' === $value || isset($parts[$key])) {
                throw ProjectException::invalid('rrule has an empty or repeated part: '.$key);
            }
            $parts[$key] = $value;
        }

        self::check($parts);

        return implode(';', array_map(static fn (string $k, string $v): string => $k.'='.$v, array_keys($parts), $parts));
    }

    /**
     * @param array<string, string> $parts
     */
    private static function check(array $parts): void
    {
        if (!\in_array($parts['FREQ'] ?? '', self::FREQUENCIES, true)) {
            throw ProjectException::invalid('rrule needs FREQ=DAILY, WEEKLY, MONTHLY or YEARLY');
        }
        foreach ($parts as $key => $value) {
            $ok = match ($key) {
                'FREQ' => true,
                'INTERVAL' => self::inRange($value, self::MAX_INTERVAL),
                'COUNT' => self::inRange($value, self::MAX_COUNT),
                'UNTIL' => 1 === preg_match(self::UNTIL, $value),
                'BYDAY' => self::days($value),
                default => false,
            };
            if (!$ok) {
                throw ProjectException::invalid('rrule part is not supported: '.$key);
            }
        }
        if (isset($parts['COUNT'], $parts['UNTIL'])) {
            throw ProjectException::invalid('rrule takes COUNT or UNTIL, not both');
        }
    }

    private static function inRange(string $value, int $max): bool
    {
        return 1 === preg_match('/^\d{1,3}$/', $value) && (int) $value >= 1 && (int) $value <= $max;
    }

    private static function days(string $value): bool
    {
        $days = explode(',', $value);
        if (\count($days) > 7 || \count($days) !== \count(array_unique($days))) {
            return false;
        }
        foreach ($days as $day) {
            if (1 !== preg_match(self::DAY, $day)) {
                return false;
            }
        }

        return true;
    }
}
