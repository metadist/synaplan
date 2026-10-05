<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Scheduler;

use App\Service\Scheduler\ScheduledJobs;
use PHPUnit\Framework\TestCase;

final class ScheduledJobsTest extends TestCase
{
    public function testLanesMatchTheSchedulerContract(): void
    {
        self::assertSame([
            'tick' => [
                'app:media:reap-jobs',
                'app:chat:reap-stuck',
                'app:desktop:reap-jobs',
                'app:process-mail-handlers',
                'app:process-emails',
            ],
            'tasks' => [
                'app:saved-tasks:tick',
            ],
            'hourly' => [
                'app:files:reap-ephemeral',
                'app:approvals:expire',
                'app:models:discover',
            ],
            'daily' => [
                'app:updates:check',
                'app:models:check-availability',
                'app:digest:run',
                'app:selfaware:sync-docs',
                'app:approvals:digest',
                'app:sync-model-prices',
            ],
            'health' => [
                'app:model:health-check',
            ],
        ], ScheduledJobs::LANES);
    }

    public function testAllFollowsLaneOrderAndLaneOfResolvesEachCommand(): void
    {
        $flat = [];
        foreach (ScheduledJobs::LANES as $lane => $commands) {
            foreach ($commands as $command) {
                $flat[] = $command;
                self::assertSame($lane, ScheduledJobs::laneOf($command));
            }
        }

        self::assertSame($flat, ScheduledJobs::all());
        self::assertSame($flat, array_values(array_unique($flat)));
        self::assertNull(ScheduledJobs::laneOf('app:scheduler:claim'));
        self::assertNull(ScheduledJobs::laneOf('app:scheduler:status'));
    }
}
