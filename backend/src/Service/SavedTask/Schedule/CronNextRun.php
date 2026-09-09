<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Schedule;

/**
 * Next-run calculator for a 5-field cron expression (min hour day month weekday).
 */
final class CronNextRun
{
    public function next(string $expression, \DateTimeImmutable $from, \DateTimeZone $tz): \DateTimeImmutable
    {
        $parts = preg_split('/\s+/', trim($expression)) ?: [];
        if (5 !== count($parts)) {
            throw new \InvalidArgumentException('Cron expression must have 5 fields');
        }

        $localNow = $from->setTimezone($tz);
        $local = $localNow->setTime((int) $localNow->format('H'), (int) $localNow->format('i'), 0);
        if ($local <= $localNow) {
            $local = $local->modify('+1 minute');
        }

        for ($i = 0; $i < 366 * 24 * 60; ++$i) {
            if ($this->matches($parts, $local)) {
                return $local->setTimezone(new \DateTimeZone('UTC'));
            }
            $local = $local->modify('+1 minute');
        }

        throw new \InvalidArgumentException('Could not compute the next cron run');
    }

    /**
     * @param list<string> $parts
     */
    private function matches(array $parts, \DateTimeImmutable $local): bool
    {
        $values = [
            (int) $local->format('i'),
            (int) $local->format('G'),
            (int) $local->format('j'),
            (int) $local->format('n'),
            (int) $local->format('w'),
        ];
        foreach ($parts as $i => $field) {
            if (!$this->fieldMatches($field, $values[$i], $i)) {
                return false;
            }
        }

        return true;
    }

    private function fieldMatches(string $field, int $value, int $index): bool
    {
        if ('*' === $field) {
            return true;
        }
        if (1 === preg_match('#^\*/(\d+)$#', $field, $m)) {
            $step = (int) $m[1];

            return $step > 0 && 0 === $value % $step;
        }
        if (str_contains($field, '-')) {
            [$from, $to] = array_map(intval(...), explode('-', $field, 2));
            if (4 === $index && 7 === $value) {
                $value = 0;
            }

            return $value >= $from && $value <= $to;
        }
        $allowed = array_map(intval(...), explode(',', $field));
        if (4 === $index && (in_array(7, $allowed, true) || in_array(0, $allowed, true))) {
            return in_array($value, $allowed, true) || (0 === $value && in_array(7, $allowed, true));
        }

        return in_array($value, $allowed, true);
    }
}
