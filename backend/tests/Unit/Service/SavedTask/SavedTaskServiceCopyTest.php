<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Agent;
use App\Entity\Prompt;
use App\Entity\SavedTask;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\PromptRepository;
use App\Repository\SavedTaskRepository;
use App\Repository\SavedTaskRunRepository;
use App\Service\Iam\AccessGate;
use App\Service\Iam\Exception\AssistantNotSharedException;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ResourceKind\SavedTaskKind;
use App\Service\SavedTask\Graph\SavedTaskGraphCapture;
use App\Service\SavedTask\Graph\SavedTaskGraphPortability;
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

    public function testCopyOfAssistantTaskKeepsThatInstructionWhenUseIsGranted(): void
    {
        $source = new SavedTask(9, 21, 'Reply');
        $source->setTrigger(SavedTask::TRIGGER_SCHEDULE, [
            'kind' => 'daily',
            'at' => '07:00',
            'agentId' => 1,
            'agentTrigger' => 'new-assistant:sch-1',
        ]);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('agent:new-assistant');
        $prompt->setPrompt('Reply with ping.');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 21);

        $agent = new Agent(9, 21, 'new-assistant', 'Ping bot', []);
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 1);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturnCallback(
            static function (User $_user, string $kind, string $id, Permission $_perm): bool {
                return SavedTaskKind::KEY === $kind || (AgentKind::KEY === $kind && '1' === $id);
            }
        );

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->with(21)->willReturn($prompt);
        $prompts->expects(self::never())->method('findFirstUsableForUser');

        $agents = $this->createMock(AgentRepository::class);
        $agents->method('findByPromptIdAndOwner')->with(21, 9)->willReturn($agent);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->expects(self::once())->method('save');

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
            null,
            null,
            $agents,
        );

        $result = $service->copyForOwner($source, $user);

        self::assertSame(21, $result->task->getPromptId());
        self::assertSame(1, $result->task->getTriggerConfig()['agentId'] ?? null);
        self::assertArrayNotHasKey('agentTrigger', $result->task->getTriggerConfig() ?? []);
        self::assertSame([], $result->checklist);
    }

    public function testCopyOfAssistantTaskRefusesAForeignPrompt(): void
    {
        $source = new SavedTask(9, 21, 'Reply');
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('agent:new-assistant');
        $prompt->setPrompt('Reply with ping.');
        (new \ReflectionProperty(Prompt::class, 'id'))->setValue($prompt, 21);

        $agent = new Agent(9, 21, 'new-assistant', 'Ping bot', []);
        (new \ReflectionProperty(Agent::class, 'id'))->setValue($agent, 1);

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturnCallback(
            static function (User $_user, string $kind, string $_id, Permission $_perm): bool {
                return SavedTaskKind::KEY === $kind;
            }
        );

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->with(21)->willReturn($prompt);
        $prompts->expects(self::never())->method('findFirstUsableForUser');

        $agents = $this->createMock(AgentRepository::class);
        $agents->method('findByPromptIdAndOwner')->willReturn($agent);

        $service = new SavedTaskService(
            $this->createStub(SavedTaskRepository::class),
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
            null,
            null,
            null,
            null,
            $agents,
        );

        $this->expectException(AssistantNotSharedException::class);
        try {
            $service->copyForOwner($source, $user);
        } catch (AssistantNotSharedException $e) {
            self::assertSame('Ping bot', $e->assistantName);
            throw $e;
        }
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

    public function testCopyPortsMcpToolsThroughServerNames(): void
    {
        $source = new SavedTask(9, 5, 'Ticket');
        $source->setGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'mcp:9:create']],
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

        $portability = $this->createMock(SavedTaskGraphPortability::class);
        $portability->method('importTriggerConfig')->willReturnCallback(
            static fn (?array $c): array => ['config' => $c, 'checklist' => []]
        );
        $portability->expects(self::once())->method('exportGraph')->willReturn([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'mcp:Helpdesk:create']],
            ],
            'trigger' => ['type' => SavedTask::TRIGGER_MANUAL],
        ]);
        $portability->expects(self::once())->method('importGraph')->willReturn([
            'graph' => [
                'nodes' => [
                    ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'mcp:22:create']],
                ],
                'trigger' => ['type' => SavedTask::TRIGGER_MANUAL],
            ],
            'checklist' => [],
        ]);
        $portability->method('toolNames')->willReturn(['mcp:22:create']);

        $registry = $this->createMock(ToolRegistry::class);
        $registry->expects(self::once())->method('get')->with(3, 'mcp:22:create')->willReturn(null);

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
            $portability,
        );

        $result = $service->copyForOwner($source, $user);

        self::assertSame('mcp:22:create', $result->task->getGraph()['nodes'][0]['params']['tool'] ?? null);
        self::assertSame([
            ['code' => 'needsTool', 'itemKey' => 'mcp:22:create', 'detail' => 'mcp:22:create'],
        ], $result->checklist);
    }

    public function testCopyStripsInboundAccountIdAndChecklistsMailbox(): void
    {
        $source = new SavedTask(9, 5, 'Inbox');
        $source->setTrigger(SavedTask::TRIGGER_INBOUND_EMAIL, ['accountId' => 77, 'folder' => 'INBOX']);
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

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save');

        $copy = $this->service($tasks, $prompts, $gate)->copyForOwner($source, $user);

        self::assertSame(['folder' => 'INBOX'], $copy->task->getTriggerConfig());
        self::assertSame([
            ['code' => 'needsMailbox', 'itemKey' => 'inbound_email', 'detail' => 'inbound_email'],
        ], $copy->checklist);
    }

    public function testCopyRetargetsTopicIdWhenAssistantIsReplaced(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        $source->setGraph([
            'nodes' => [
                ['id' => 'n1', 'capability' => 'chat', 'depends_on' => [], 'params' => ['topic_id' => 'sales']],
            ],
        ]);
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
        $prompts->method('find')->willReturnCallback(static function (int $id) use ($prompt, $fallback): ?Prompt {
            return match ($id) {
                5 => $prompt,
                88 => $fallback,
                default => null,
            };
        });
        $prompts->method('findByTopicAndUser')->willReturn(null);
        $prompts->expects(self::any())->method('findFirstUsableForUser')->with(3)->willReturn($fallback);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save');

        $portability = new SavedTaskGraphPortability(
            $prompts,
            $this->createStub(\App\Repository\McpServerConfigRepository::class),
        );

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
            null,
            $portability,
        );

        $result = $service->copyForOwner($source, $user);
        $params = $result->task->getGraph()['nodes'][0]['params'] ?? [];

        self::assertSame('88', $params['prompt_id'] ?? null);
        self::assertSame('general', $params['topic_id'] ?? null);
        self::assertContains('needsAssistant', array_column($result->checklist, 'code'));
    }

    public function testCopyComputesNextRunAtForASchedule(): void
    {
        $source = new SavedTask(9, 5, 'Weekly');
        $source->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'daily', 'at' => '07:00']);
        (new \ReflectionProperty(SavedTask::class, 'id'))->setValue($source, 11);

        $prompt = new Prompt();
        $prompt->setOwnerId(3);
        $prompt->setTopic('sales');
        $prompt->setPrompt('Help.');

        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(3);

        $gate = $this->createMock(AccessGate::class);
        $gate->method('decide')->willReturn(true);

        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        $next = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));
        $parser = $this->createMock(ScheduleParser::class);
        $parser->expects(self::once())->method('nextRunAt')->willReturn($next);

        $tasks = $this->createMock(SavedTaskRepository::class);
        $tasks->method('save');

        $copy = $this->service($tasks, $prompts, $gate, $parser)->copyForOwner($source, $user)->task;

        self::assertEquals($next, $copy->getNextRunAt());
        self::assertFalse($copy->isEnabled());
    }

    private function service(
        SavedTaskRepository $tasks,
        PromptRepository $prompts,
        AccessGate $gate,
        ?ScheduleParser $parser = null,
    ): SavedTaskService {
        return new SavedTaskService(
            $tasks,
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            $this->createStub(SavedTaskGraphValidator::class),
            $parser ?? $this->createStub(ScheduleParser::class),
            $gate,
            $this->createStub(SavedTaskGraphCapture::class),
        );
    }
}
