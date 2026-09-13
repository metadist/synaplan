<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Exception\AssistantNotSharedException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use App\Service\SavedTask\Graph\SavedTaskGraphCapture;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\SavedTaskService;
use App\Service\SavedTask\Schedule\ScheduleParser;
use App\Service\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

final class SavedTaskServiceCopyTest extends TestCase
{
    public function testCopyKeepsTriggerPausedAndStripsSecrets(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        $source->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'daily', 'at' => '07:00']);
        $source->setAllowUnattended(true);
        $source->setGraph(['nodes' => [
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in', 'secret' => 'owner-only']],
        ]]);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('sales');
        $prompt->setShortDescription('Sales');
        $prompt->setPrompt('Help sales.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturn(true);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::atLeastOnce())
            ->method('find')
            ->with(5)
            ->willReturn($prompt);

        $saved = null;
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->expects(self::once())
            ->method('save')
            ->willReturnCallback(static function (SavedTask $copy) use (&$saved): void {
                $saved = $copy;
            });

        $service = new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
        );

        $result = $service->copyForOwner($source, $user);
        $copy = $result->task;

        self::assertSame($saved, $copy);
        self::assertSame(SavedTask::TRIGGER_SCHEDULE, $copy->getTriggerType());
        self::assertSame(['kind' => 'daily', 'at' => '07:00'], $copy->getTriggerConfig());
        self::assertFalse($copy->isEnabled());
        self::assertFalse($copy->allowsUnattended());
        self::assertNull($copy->getChatId());
        self::assertSame(3, $copy->getOwnerId());
        self::assertSame([], $result->checklist);
        self::assertSame([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in']],
            ],
            'trigger' => ['type' => SavedTask::TRIGGER_SCHEDULE],
        ], $copy->getGraph());
    }

    public function testCopyWithoutAssistantAccessUsesFallbackAndChecklist(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('sales');
        $prompt->setPrompt('Help.');

        $fallback = new Prompt();
        $fallback->setOwnerId(3);
        $fallback->setTopic('general');
        $fallback->setPrompt('Hi.');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($fallback, 88);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturnCallback(
            static function (User $_user, string $kind, string $_id, Permission $_perm): bool {
                return SavedTaskKind::KEY === $kind;
            }
        );

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::any())->method('find')->with(5)->willReturn($prompt);
        $prompts->expects(self::any())->method('findFirstUsableForUser')->with(3)->willReturn($fallback);

        $saved = null;
        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save')->willReturnCallback(static function (SavedTask $copy) use (&$saved): void {
            $saved = $copy;
        });

        $service = new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
        );

        $result = $service->copyForOwner($source, $user);

        self::assertSame(88, $result->task->getPromptId());
        self::assertFalse($result->task->isEnabled());
        self::assertSame([
            ['code' => 'needsAssistant', 'itemKey' => 'sales', 'detail' => 'sales'],
        ], $result->checklist);
        self::assertSame($saved, $result->task);
    }

    public function testCopyWithoutAssistantOrFallbackThrowsConflict(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('sales');
        $prompt->setPrompt('Help.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturnCallback(
            static function (User $_user, string $kind, string $_id, Permission $_perm): bool {
                return SavedTaskKind::KEY === $kind;
            }
        );

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->expects(self::any())->method('find')->with(5)->willReturn($prompt);
        $prompts->method('findFirstUsableForUser')->willReturn(null);

        $service = new SavedTaskService(
            $this->createStub(SavedTaskRepository::class),
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
        );

        $this->expectException(AssistantNotSharedException::class);
        $this->expectExceptionMessage('iam.assistantNotShared');
        $service->copyForOwner($source, $user);
    }

    public function testCopyRequiresUseOnTheTask(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->expects(self::once())
            ->method('decide')
            ->with($user, SavedTaskKind::KEY, '11', Permission::Use)
            ->willReturn(false);

        $service = new SavedTaskService(
            $this->createStub(SavedTaskRepository::class),
            $this->createStub(SavedTaskRunRepository::class),
            $this->createStub(PromptRepository::class),
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
        );

        $this->expectException(\App\Service\SavedTask\SavedTaskNotFoundException::class);
        $service->copyForOwner($source, $user);
    }

    public function testCopyListsMissingTools(): void
    {
        $source = new SavedTask(9, 5, 'Ticket');
        $source->setGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'custom:helpdesk']],
            ],
        ]);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(3);
        $prompt->setTopic('support');
        $prompt->setPrompt('Help.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturn(true);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('get')->with(3, 'custom:helpdesk')->willReturn(null);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save');

        $service = new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
            null,
            null,
            $registry,
        );

        $result = $service->copyForOwner($source, $user);

        self::assertSame([
            ['code' => 'needsTool', 'itemKey' => 'custom:helpdesk', 'detail' => 'custom:helpdesk'],
        ], $result->checklist);
    }

    public function testCopyRegeneratesWebhookToken(): void
    {
        $source = new SavedTask(9, 5, 'Hook');
        $source->setTrigger(SavedTask::TRIGGER_WEBHOOK, ['token' => 'old-token', 'hmacSecret' => 'old-secret']);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(3);
        $prompt->setTopic('general');
        $prompt->setPrompt('Hi.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturn(true);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save');

        $service = new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
        );

        $copy = $service->copyForOwner($source, $user)->task;
        $config = $copy->getTriggerConfig() ?? [];

        self::assertSame(SavedTask::TRIGGER_WEBHOOK, $copy->getTriggerType());
        self::assertNotSame('old-token', $config['token'] ?? null);
        self::assertArrayNotHasKey('hmacSecret', $config);
        self::assertFalse($copy->isEnabled());
    }
}
