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
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Security\SsrfGuard;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolRegistry;
use App\Service\Tool\ToolSource;
use PHPUnit\Framework\TestCase;

/**
 * Builder-specific save rules: an inbound webhook runs unattended, an outbound
 * step's secret never round-trips through the editor, and a "Use a tool" step
 * must point at a tool the owner has actually connected.
 */
final class SavedTaskServiceBuilderTest extends TestCase
{
    public function testAWebhookStartWithSendingStepsNeedsTheUnattendedConfirmation(): void
    {
        $task = new SavedTask(9, 5, 'From n8n');
        $task->setGraph($this->graph([
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in']],
        ], SavedTask::TRIGGER_WEBHOOK));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Another system starting this would send or save files on its own');

        $this->service()->update($task, ['triggerType' => SavedTask::TRIGGER_WEBHOOK, 'triggerConfig' => []]);
    }

    public function testASavedOutboundSecretSurvivesAnEditThatOmitsIt(): void
    {
        $task = $this->manualTaskWithSecret('keep-me');

        $edited = $this->graph([
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/other', 'secretConfigured' => true]],
        ]);
        $this->service()->update($task, ['graph' => $edited]);

        $params = $task->getGraph()['nodes'][0]['params'] ?? [];
        self::assertSame('https://hooks.example/other', $params['url'] ?? null);
        self::assertSame('keep-me', $params['secret'] ?? null);
        self::assertArrayNotHasKey('secretConfigured', $params);
    }

    public function testTypingANewSecretReplacesItAndClearingRemovesIt(): void
    {
        $task = $this->manualTaskWithSecret('old');
        $service = $this->service();

        $service->update($task, ['graph' => $this->graph([
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in', 'secret' => 'new']],
        ])]);
        self::assertSame('new', $task->getGraph()['nodes'][0]['params']['secret'] ?? null);

        $service->update($task, ['graph' => $this->graph([
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in', 'secret' => '']],
        ])]);
        self::assertSame('', $task->getGraph()['nodes'][0]['params']['secret'] ?? null);
    }

    public function testAToolStepMustUseAConnectedToolThatCanRunAsAStep(): void
    {
        $task = new SavedTask(9, 5, 'Tools');
        $registry = $this->createMock(ToolRegistry::class);
        $registry->method('get')->willReturnCallback(static function (int $userId, string $name): ?ToolDescriptor {
            return match ($name) {
                'custom:crm' => new ToolDescriptor('custom:crm', 'CRM', '', [], SideEffect::Write, ToolSource::Custom, $userId),
                'web_search' => new ToolDescriptor('web_search', 'Search', '', [], SideEffect::Read, ToolSource::Builtin, 0),
                default => null,
            };
        });
        $service = $this->service($registry);

        $service->update($task, ['graph' => $this->graph([
            ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'custom:crm']],
        ])]);
        self::assertSame('custom:crm', $task->getGraph()['nodes'][0]['params']['tool'] ?? null);

        try {
            $service->update($task, ['graph' => $this->graph([
                ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'custom:gone']],
            ])]);
            self::fail('A tool nobody connected must be rejected');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Step 1 uses a tool that is not connected', $e->getMessage());
        }

        try {
            $service->update($task, ['graph' => $this->graph([
                ['id' => 'n1', 'capability' => 'tool_call', 'depends_on' => [], 'params' => ['tool' => 'web_search']],
            ])]);
            self::fail('A built-in chat tool cannot run as a step');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Step 1 uses a tool that cannot run as a step', $e->getMessage());
        }
    }

    private function manualTaskWithSecret(string $secret): SavedTask
    {
        $task = new SavedTask(9, 5, 'Outbound');
        $task->setGraph($this->graph([
            ['id' => 'n1', 'capability' => 'outbound_webhook', 'depends_on' => [], 'params' => ['url' => 'https://hooks.example/in', 'secret' => $secret]],
        ]));

        return $task;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     *
     * @return array<string, mixed>
     */
    private function graph(array $nodes, string $trigger = SavedTask::TRIGGER_MANUAL): array
    {
        return ['version' => 1, 'trigger' => ['type' => $trigger], 'nodes' => $nodes];
    }

    private function service(?ToolRegistry $registry = null): SavedTaskService
    {
        $prompt = new Prompt();
        $prompt->setOwnerId(9);
        $prompt->setTopic('saved-1');
        $prompt->setPrompt('Do the thing.');
        $prompts = $this->createMock(PromptRepository::class);
        $prompts->method('find')->willReturn($prompt);

        $workflows = $this->createMock(WorkflowsConfig::class);
        $workflows->method('isBuilderEnabled')->willReturn(true);
        $ssrf = $this->createMock(SsrfGuard::class);
        $ssrf->method('isBlockedUrl')->willReturn(false);

        return new SavedTaskService(
            $this->createStub(SavedTaskRepository::class),
            $this->createStub(SavedTaskRunRepository::class),
            $prompts,
            new SavedTaskGraphValidator($workflows, $ssrf),
            $this->createStub(ScheduleParser::class),
            $this->createStub(AccessGate::class),
            $this->createStub(SavedTaskGraphCapture::class),
            null,
            $workflows,
            $registry,
        );
    }
}
