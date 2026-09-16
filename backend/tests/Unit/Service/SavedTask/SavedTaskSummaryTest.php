<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Service\SavedTask\Graph\SavedTaskSummary;
use PHPUnit\Framework\TestCase;

/**
 * The summary contract with the frontend card: language-neutral CODES only.
 * A single English word leaking into params ends up inside a translated
 * sentence and produces the German/English hybrid the card shipped with once.
 */
final class SavedTaskSummaryTest extends TestCase
{
    private SavedTaskSummary $summary;

    protected function setUp(): void
    {
        $this->summary = new SavedTaskSummary();
    }

    public function testInstructionOnlyTaskUsesTheSimpleSentence(): void
    {
        $task = new SavedTask(1, 12, 'Katzenbild');

        $result = $this->summary->describe($task);

        self::assertSame(SavedTaskSummary::KEY_SIMPLE, $result['key']);
        self::assertSame(['when' => 'manual'], $result['params']);
    }

    public function testDailyScheduleEmitsCodesAndInterpolationValues(): void
    {
        $task = new SavedTask(1, 12, 'Morning digest');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'daily', 'at' => '07:00', 'tz' => 'Europe/Berlin']);

        $result = $this->summary->describe($task);

        self::assertSame(
            ['when' => 'daily', 'at' => '07:00', 'tz' => 'Europe/Berlin'],
            $result['params'],
        );
    }

    public function testIntervalScheduleEmitsMinutes(): void
    {
        $task = new SavedTask(1, 12, 'Hourly check');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'interval', 'every_minutes' => 60]);

        $result = $this->summary->describe($task);

        self::assertSame(['when' => 'interval', 'minutes' => '60'], $result['params']);
    }

    public function testGraphTaskMapsCapabilitiesToReadAndSaveCodes(): void
    {
        $task = new SavedTask(1, 12, 'Mail triage');
        $task->setGraph(['nodes' => [
            ['capability' => 'email_search'],
            ['capability' => 'email_me'],
        ]]);

        $result = $this->summary->describe($task);

        self::assertSame(SavedTaskSummary::KEY_WITH_STEPS, $result['key']);
        self::assertSame('mailbox', $result['params']['reads']);
        self::assertSame('email', $result['params']['saves']);
    }

    public function testGraphWithoutSaveCapabilityFallsBackToReply(): void
    {
        $task = new SavedTask(1, 12, 'Research');
        $task->setGraph(['nodes' => [['capability' => 'web_search']]]);

        $result = $this->summary->describe($task);

        self::assertSame('instruction', $result['params']['reads']);
        self::assertSame('reply', $result['params']['saves']);
    }

    public function testEveryParamIsACodeOrInterpolationValueNeverProse(): void
    {
        $task = new SavedTask(1, 12, 'Any');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'weekly', 'at' => '08:30', 'tz' => 'UTC']);
        $task->setGraph(['nodes' => [['capability' => 'save_to_folder']]]);

        $result = $this->summary->describe($task);

        foreach ($result['params'] as $value) {
            self::assertDoesNotMatchRegularExpression('/\s/', $value, 'params must never contain sentences');
        }
    }
}
