<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Entity\SavedTaskRun;
use App\Repository\ConfigRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\SavedTask\SavedTaskConfig;
use App\Service\SavedTask\SavedTaskRunner;
use App\Service\SavedTask\SavedTaskTickService;
use App\Service\SavedTask\Schedule\ScheduleParser;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class SavedTaskTickServiceTest extends TestCase
{
    private const NOW = '2026-10-05 06:00:00';

    public function testInterruptedRunsGetATerminalStateBeforeDueTasksRun(): void
    {
        $now = $this->now();
        $tasks = $this->createStub(SavedTaskRepository::class);
        $tasks->method('findDueScheduled')->willReturn([]);
        $runs = $this->createMock(SavedTaskRunRepository::class);
        $runs->expects(self::once())
            ->method('failAbandoned')
            ->with(
                self::callback(static fn (\DateTimeImmutable $cutoff): bool => $cutoff->getTimestamp() === $now->getTimestamp() - SavedTaskTickService::ABANDONED_RUN_SECONDS),
                SavedTaskTickService::ABANDONED_RUN_ERROR,
            )
            ->willReturn(2);

        $result = $this->service($tasks, $runs, $this->createStub(SavedTaskRunner::class))->tick($now);

        self::assertSame(['claimed' => 0, 'ran' => 0, 'failed' => 0, 'skipped' => 0], $result);
    }

    public function testOccurrenceIsSkippedWhileThePreviousRunIsStillGoing(): void
    {
        $task = $this->weeklyTask(11);
        $tasks = $this->createStub(SavedTaskRepository::class);
        $tasks->method('findDueScheduled')->willReturn([$task]);
        $tasks->method('claim')->willReturn(true);
        $runs = $this->createMock(SavedTaskRunRepository::class);
        $runs->expects(self::once())->method('hasActiveRunForTask')->with(11)->willReturn(true);
        $runner = $this->createMock(SavedTaskRunner::class);
        $runner->expects(self::never())->method('run');

        $result = $this->service($tasks, $runs, $runner)->tick($this->now());

        self::assertSame(['claimed' => 1, 'ran' => 0, 'failed' => 0, 'skipped' => 1], $result);
    }

    public function testWeeklyTaskRunsAndMovesToNextWeek(): void
    {
        $task = $this->weeklyTask(11);
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('findDueScheduled')->willReturn([$task]);
        $tasks->expects(self::once())
            ->method('claim')
            ->with(
                $task,
                self::anything(),
                self::callback(static fn (\DateTimeImmutable $next): bool => '2026-10-12 06:00:00' === $next->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')),
            )
            ->willReturn(true);
        $runs = $this->createStub(SavedTaskRunRepository::class);
        $runs->method('hasActiveRunForTask')->willReturn(false);

        $run = new SavedTaskRun(11, 'schedule');
        $run->markCompleted(null, null);
        $runner = $this->createMock(SavedTaskRunner::class);
        $runner->expects(self::once())
            ->method('run')
            ->with(7, 11, '', 'schedule')
            ->willReturn(['run' => $run, 'task' => $task]);

        $result = $this->service($tasks, $runs, $runner)->tick($this->now());

        self::assertSame(['claimed' => 1, 'ran' => 1, 'failed' => 0, 'skipped' => 0], $result);
    }

    private function service(SavedTaskRepository $tasks, SavedTaskRunRepository $runs, SavedTaskRunner $runner): SavedTaskTickService
    {
        $config = $this->createStub(SavedTaskConfig::class);
        $config->method('isEnabled')->willReturn(true);

        return new SavedTaskTickService(
            $config,
            $this->createStub(ConfigRepository::class),
            $tasks,
            $runs,
            $runner,
            new ScheduleParser(),
            new NullLogger(),
        );
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
    }

    private function weeklyTask(int $id): SavedTask
    {
        $task = new SavedTask(7, 1, 'Weekly report');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'cron', 'expression' => '0 8 * * 1', 'tz' => 'Europe/Berlin']);
        $task->setNextRunAt($this->now());
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($task, $id);

        return $task;
    }
}
