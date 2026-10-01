<?php

declare(strict_types=1);

namespace App\Service\Scheduler;

/**
 * Commands the scheduler runs, one list per lane, in run order.
 *
 * The container scheduler greps this file for the command names it starts,
 * so each name stays a single-quoted literal.
 */
final class ScheduledJobs
{
    public const LANE_TICK = 'tick';
    public const LANE_TASKS = 'tasks';
    public const LANE_HOURLY = 'hourly';
    public const LANE_DAILY = 'daily';
    public const LANE_HEALTH = 'health';

    /**
     * @var array<string, list<string>>
     */
    public const LANES = [
        self::LANE_TICK => [
            'app:media:reap-jobs',
            'app:chat:reap-stuck',
            'app:desktop:reap-jobs',
            'app:process-mail-handlers',
            'app:process-emails',
        ],
        self::LANE_TASKS => [
            'app:saved-tasks:tick',
        ],
        self::LANE_HOURLY => [
            'app:files:reap-ephemeral',
            'app:approvals:expire',
            'app:models:discover',
        ],
        self::LANE_DAILY => [
            'app:updates:check',
            'app:models:check-availability',
            'app:digest:run',
            'app:selfaware:sync-docs',
            'app:approvals:digest',
            'app:sync-model-prices',
        ],
        self::LANE_HEALTH => [
            'app:model:health-check',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        $commands = [];
        foreach (self::LANES as $lane) {
            foreach ($lane as $command) {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    public static function laneOf(string $command): ?string
    {
        foreach (self::LANES as $lane => $commands) {
            if (in_array($command, $commands, true)) {
                return $lane;
            }
        }

        return null;
    }
}
