<?php

declare(strict_types=1);

namespace App\Service\SavedTask\Schedule;

final class ScheduleParser
{
    public const MIN_MINUTES = 15;

    /**
     * @param array<string, mixed>|null $config
     */
    public function nextRunAt(?array $config, \DateTimeImmutable $nowUtc): \DateTimeImmutable
    {
        if (null === $config) {
            throw new \InvalidArgumentException('Schedule is missing');
        }

        $kind = $config['kind'] ?? '';
        $tzName = is_string($config['tz'] ?? null) && '' !== $config['tz'] ? $config['tz'] : 'UTC';
        try {
            $tz = new \DateTimeZone($tzName);
        } catch (\Exception) {
            throw new \InvalidArgumentException(sprintf('Unknown timezone "%s"', $tzName));
        }

        $localNow = $nowUtc->setTimezone($tz);

        return match ($kind) {
            'interval' => $this->nextInterval($config, $nowUtc),
            'daily' => $this->nextDaily($config, $localNow),
            'weekly' => $this->nextWeekly($config, $localNow),
            default => throw new \InvalidArgumentException('Unsupported schedule kind'),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nextInterval(array $config, \DateTimeImmutable $nowUtc): \DateTimeImmutable
    {
        $minutes = (int) ($config['every_minutes'] ?? 0);
        if (!in_array($minutes, [15, 30, 60], true)) {
            throw new \InvalidArgumentException('Interval must be 15, 30 or 60 minutes');
        }

        return $nowUtc->modify('+'.$minutes.' minutes');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nextDaily(array $config, \DateTimeImmutable $localNow): \DateTimeImmutable
    {
        $at = $this->parseAt($config['at'] ?? null);
        $candidate = $localNow->setTime($at[0], $at[1], 0);
        if ($candidate <= $localNow) {
            $candidate = $candidate->modify('+1 day');
        }

        return $candidate->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function nextWeekly(array $config, \DateTimeImmutable $localNow): \DateTimeImmutable
    {
        $at = $this->parseAt($config['at'] ?? null);
        $days = $config['days'] ?? [1, 2, 3, 4, 5];
        if (!is_array($days) || [] === $days) {
            $days = [1, 2, 3, 4, 5];
        }
        $wanted = [];
        foreach ($days as $day) {
            $wanted[(int) $day] = true;
        }

        for ($i = 0; $i < 8; ++$i) {
            $candidate = $localNow->modify('+'.$i.' day')->setTime($at[0], $at[1], 0);
            $dow = (int) $candidate->format('N');
            if (isset($wanted[$dow]) && $candidate > $localNow) {
                return $candidate->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        throw new \InvalidArgumentException('Could not compute the next run');
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function parseAt(mixed $at): array
    {
        if (!is_string($at) || 1 !== preg_match('/^(\d{2}):(\d{2})$/', $at, $m)) {
            throw new \InvalidArgumentException('Time must be HH:MM');
        }
        $hour = (int) $m[1];
        $minute = (int) $m[2];
        if ($hour > 23 || $minute > 59) {
            throw new \InvalidArgumentException('Time must be HH:MM');
        }

        return [$hour, $minute];
    }
}
