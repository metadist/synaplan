<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Iam\AccessGate;
use App\Service\SavedTask\Graph\SavedTaskGraphCapture;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\SavedTaskService;
use App\Service\SavedTask\Schedule\ScheduleParser;
use PHPUnit\Framework\TestCase;

/**
 * "Schedule this" must pin the executed steps, and changing the trigger must
 * keep them — otherwise a rerun silently re-plans and can lose url_fetch /
 * email_me on the way.
 */
final class SavedTaskServiceGraphTest extends TestCase
{
    /** @var array<string, mixed> */
    private const GRAPH = [
        'version' => 1,
        'trigger' => ['type' => SavedTask::TRIGGER_MANUAL],
        'reply_node' => 'n2',
        'nodes' => [
            ['id' => 'n1', 'capability' => 'url_fetch', 'depends_on' => [], 'inputs' => ['urls' => ['https://example.com/stock']], 'params' => []],
            ['id' => 'n2', 'capability' => 'chat', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text'], 'params' => []],
            ['id' => 'n3', 'capability' => 'email_me', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text'], 'params' => []],
        ],
    ];

    public function testCreateFromAChatTurnStoresTheExecutedPlanAsGraph(): void
    {
        $capture = $this->createMock(SavedTaskGraphCapture::class);
        $capture->expects(self::once())
            ->method('fromMessage')
            ->with(4711, 9, SavedTask::TRIGGER_MANUAL)
            ->willReturn(self::GRAPH);

        $saved = null;
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('findByPromptAndOwner')->willReturn(null);
        $tasks->method('save')->willReturnCallback(static function (SavedTask $task) use (&$saved): void {
            $saved = $task;
        });

        $service = $this->service($tasks, $capture);

        $task = $service->create(9, 5, 'RioTinto', 4711);

        self::assertSame($saved, $task);
        self::assertSame(self::GRAPH, $task->getGraph());
    }

    public function testCreateWithoutASourceMessageLeavesTheGraphEmpty(): void
    {
        $capture = $this->createMock(SavedTaskGraphCapture::class);
        $capture->expects(self::never())->method('fromMessage');

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('findByPromptAndOwner')->willReturn(null);

        $task = $this->service($tasks, $capture)->create(9, 5, 'Plain');

        self::assertNull($task->getGraph());
    }

    public function testCreateIgnoresAGraphTheValidatorRejects(): void
    {
        $capture = $this->createMock(SavedTaskGraphCapture::class);
        $capture->method('fromMessage')->willReturn(['version' => 1, 'trigger' => ['type' => 'manual'], 'nodes' => 'broken']);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('findByPromptAndOwner')->willReturn(null);

        $task = $this->service($tasks, $capture)->create(9, 5, 'Broken', 4711);

        self::assertNull($task->getGraph());
    }

    public function testSwitchingTheTriggerKeepsThePinnedStepsValid(): void
    {
        $task = new SavedTask(9, 5, 'RioTinto');
        $task->setGraph(self::GRAPH);

        $parser = $this->createMock(ScheduleParser::class);
        $parser->method('nextRunAt')->willReturn(new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')));

        $service = $this->service($this->createStub(SavedTaskRepository::class), $this->createStub(SavedTaskGraphCapture::class), $parser);

        // The card sends allowUnattended alongside the schedule: the pinned
        // email_me step is a mutating action and must be confirmed once.
        $service->update($task, [
            'triggerType' => SavedTask::TRIGGER_SCHEDULE,
            'triggerConfig' => ['kind' => 'daily', 'at' => '09:15', 'tz' => 'Europe/Berlin'],
            'allowUnattended' => true,
        ]);

        $graph = $task->getGraph();
        self::assertNotNull($graph);
        self::assertSame(['type' => SavedTask::TRIGGER_SCHEDULE], $graph['trigger']);
        // Only the trigger moved; the steps are untouched.
        self::assertSame(self::GRAPH['nodes'], $graph['nodes']);
        self::assertSame('n2', $graph['reply_node']);
        // And the result is exactly what the run-time factory will validate.
        self::assertSame([], (new SavedTaskGraphValidator())->validate($graph, $task->getTriggerType(), $task->getTriggerConfig()));
    }

    public function testSchedulingPinnedMailStepsNeedsTheUnattendedConfirmation(): void
    {
        // Before the steps were pinned the graph was empty and this guard never
        // fired for chat-saved tasks; now the mail step is visible to it.
        $task = new SavedTask(9, 5, 'RioTinto');
        $task->setGraph(self::GRAPH);

        $service = $this->service($this->createStub(SavedTaskRepository::class), $this->createStub(SavedTaskGraphCapture::class));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runs on its own');

        $service->update($task, [
            'triggerType' => SavedTask::TRIGGER_SCHEDULE,
            'triggerConfig' => ['kind' => 'daily', 'at' => '09:15', 'tz' => 'Europe/Berlin'],
        ]);
    }

    private function service(SavedTaskRepository $tasks, SavedTaskGraphCapture $capture, ?ScheduleParser $parser = null): SavedTaskService
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('saved-1');
        $prompt->setPrompt('Look up the stock and mail me.');

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        return new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            new SavedTaskGraphValidator(),
            $parser ?? $this->createStub(ScheduleParser::class),
            $this->createStub(AccessGate::class),
            $capture,
        );
    }
}
