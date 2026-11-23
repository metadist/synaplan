<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\SavedTask;
use App\Service\SavedTask\SavedTaskDelivery;
use PHPUnit\Framework\TestCase;

final class SavedTaskDeliveryTest extends TestCase
{
    private SavedTaskDelivery $delivery;

    protected function setUp(): void
    {
        $this->delivery = new SavedTaskDelivery();
    }

    private function task(?array $graph = null, ?array $config = null): SavedTask
    {
        $task = new SavedTask(9, 5, 'Briefing');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, $config ?? ['kind' => 'daily']);
        if (null !== $graph) {
            $task->setGraph($graph);
        }

        return $task;
    }

    /** @return array<string, mixed> */
    private function graphWith(string ...$capabilities): array
    {
        $nodes = [];
        foreach ($capabilities as $i => $capability) {
            $nodes[] = [
                'id' => 'n'.($i + 1),
                'capability' => $capability,
                'depends_on' => $i > 0 ? ['n'.$i] : [],
                'inputs' => [],
                'params' => [],
            ];
        }

        return ['version' => 1, 'trigger' => ['type' => 'schedule'], 'nodes' => $nodes];
    }

    public function testDefaultsToTheChatReply(): void
    {
        self::assertSame('reply', $this->delivery->summaryCode($this->task()));
        self::assertFalse($this->delivery->runnerMailsTheReply($this->task()));
    }

    public function testChosenEmailMakesTheRunnerMailTheReply(): void
    {
        $task = $this->task();
        $this->delivery->apply($task, SavedTaskDelivery::EMAIL);

        self::assertSame('email', $this->delivery->summaryCode($task));
        self::assertTrue($this->delivery->runnerMailsTheReply($task));
    }

    public function testAnAuthoredMailStepKeepsItsOwnPath(): void
    {
        $task = $this->task($this->graphWith('chat', 'email_me'));

        self::assertSame('email', $this->delivery->summaryCode($task));
        self::assertFalse($this->delivery->runnerMailsTheReply($task));
    }

    public function testChoosingATargetRetiresTheAuthoredMailStep(): void
    {
        $task = $this->task($this->graphWith('chat', 'email_me'));
        $this->delivery->apply($task, SavedTaskDelivery::EMAIL);

        $graph = $task->getGraph();
        self::assertNotNull($graph);
        self::assertSame(['chat'], array_column($graph['nodes'], 'capability'));
        self::assertTrue($this->delivery->runnerMailsTheReply($task));
    }

    public function testChoosingChatClearsTheEmailTarget(): void
    {
        $task = $this->task();
        $this->delivery->apply($task, SavedTaskDelivery::EMAIL);
        $this->delivery->apply($task, SavedTaskDelivery::CHAT);

        self::assertSame('reply', $this->delivery->summaryCode($task));
        self::assertFalse($this->delivery->runnerMailsTheReply($task));
    }

    public function testAuthoredSaveStepsWinOverTheChatDefault(): void
    {
        self::assertSame('folder', $this->delivery->summaryCode($this->task($this->graphWith('chat', 'save_to_folder'))));
        self::assertSame('notion', $this->delivery->summaryCode($this->task($this->graphWith('save_to_notion'))));
        self::assertSame('calendar', $this->delivery->summaryCode($this->task($this->graphWith('calendar_event'))));
    }

    public function testUnknownTargetIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->delivery->apply($this->task(), 'pigeon');
    }
}
