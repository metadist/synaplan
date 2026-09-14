<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SavedTask;

use App\Entity\Message;
use App\Entity\SavedTask;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Parallel\MediaNodeDispatcher;
use App\Service\Multitask\Execution\Parallel\MediaNodeJob;
use App\Service\Multitask\Execution\Parallel\MediaNodeRequest;
use App\Service\Multitask\Execution\Parallel\SettledMediaNodeJob;
use App\Service\Multitask\Execution\ResultAssembler;
use App\Service\Multitask\Execution\RunnerRegistry;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\MultitaskRoutingConfig;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\SavedTask\Graph\SavedTaskGraphValidator;
use App\Service\SavedTask\Graph\SavedTaskPlanFactory;
use App\Service\SavedTask\WorkflowsConfig;
use App\Service\Security\SsrfGuard;
use App\Service\Tool\Policy\ApprovalPolicy;
use App\Service\Tool\Policy\AssistantPolicyProviderInterface;
use App\Service\Tool\Policy\NullGroupPolicyProvider;
use App\Service\Tool\Policy\PolicyContext;
use App\Service\Tool\Policy\PolicyOutcome;
use App\Service\Tool\SideEffect;
use App\Service\Tool\ToolDescriptor;
use App\Service\Tool\ToolsConfig;
use App\Service\Tool\ToolSource;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * C7 + the J-TL-5 five-step graph: mail → summarize → ticket (approve) →
 * mail me → outbound webhook. Fake runners stand in for mail, the assistant,
 * the tool and the webhook receiver.
 */
final class SavedTaskRunnerWorkflowTest extends TestCase
{
    public function testFiveStepGraphPausesAtToolThenFinishes(): void
    {
        $graph = json_decode((string) file_get_contents(
            dirname(__DIR__, 3).'/Fixtures/saved_task_graphs/builder_five_step.json'
        ), true);
        self::assertIsArray($graph);

        $task = new SavedTask(4, 12, 'Monday digest');
        $task->setTrigger(SavedTask::TRIGGER_SCHEDULE, ['kind' => 'weekly', 'at' => '08:00']);
        $task->setGraph($graph);

        $config = $this->createMock(WorkflowsConfig::class);
        $config->method('isBuilderEnabled')->willReturn(true);
        $ssrf = $this->createMock(SsrfGuard::class);
        $ssrf->method('isBlockedUrl')->willReturn(false);
        $plan = (new SavedTaskPlanFactory(new SavedTaskGraphValidator($config, $ssrf)))->fromTask($task);

        self::assertSame(['n1', 'n2', 'n3', 'n4', 'n5'], array_map(
            static fn ($node): string => $node->id,
            $plan->nodes,
        ));

        $webhookBodies = [];
        $runner = $this->runner(function (TaskNode $node, NodeContext $ctx) use (&$webhookBodies): NodeResult {
            if (Capability::OutboundWebhook === $node->capability) {
                $webhookBodies[] = is_string($node->params['url'] ?? null) ? $node->params['url'] : '';

                return NodeResult::ok('posted');
            }

            return match ($node->capability) {
                Capability::EmailSearch => NodeResult::ok('3 mails from last week'),
                Capability::Chat => NodeResult::ok('Ticket: printer jam on floor 2'),
                Capability::ToolCall => $ctx->isApproved($node->id)
                    ? NodeResult::ok('ticket #88', [], ['summary' => 'ticket #88'])
                    : NodeResult::waitingApproval(7, ['summary' => 'printer jam']),
                Capability::EmailMe => NodeResult::ok('mailed ticket #88'),
                default => NodeResult::failed('unexpected '.$node->capability->value),
            };
        });

        $executor = $this->executor($runner);
        $ctx = $this->context();
        $paused = $executor->execute($plan, $ctx);
        self::assertSame('waiting_approval', $paused['node_statuses']['n3']);
        self::assertSame('done', $paused['node_statuses']['n1']);
        self::assertSame('done', $paused['node_statuses']['n2']);

        $finished = $executor->resume($plan, $ctx, 'n3', ['summary' => 'printer jam']);
        self::assertSame([
            'n1' => 'done',
            'n2' => 'done',
            'n3' => 'done',
            'n4' => 'done',
            'n5' => 'done',
        ], $finished['node_statuses']);
        self::assertSame(['https://hooks.example.com/done'], $webhookBodies);
    }

    public function testToolStepPausesWithoutAllowUnattended(): void
    {
        $task = new SavedTask(4, 12, 'Ticket');
        $task->setGraph([
            'version' => 1,
            'trigger' => ['type' => 'manual'],
            'nodes' => [[
                'id' => 'n1',
                'capability' => 'tool_call',
                'depends_on' => [],
                'params' => ['tool' => 'custom:helpdesk'],
            ]],
        ]);
        $config = $this->createMock(WorkflowsConfig::class);
        $config->method('isBuilderEnabled')->willReturn(true);
        $plan = (new SavedTaskPlanFactory(new SavedTaskGraphValidator($config)))->fromTask($task);
        $runner = $this->runner(static function (TaskNode $node, NodeContext $ctx): NodeResult {
            return $ctx->isApproved($node->id)
                ? NodeResult::ok('ticket #1')
                : NodeResult::waitingApproval(3);
        });

        $paused = $this->executor($runner)->execute($plan, $this->context(['allow_unattended' => false]));
        self::assertSame('waiting_approval', $paused['node_statuses']['n1']);
    }

    public function testNodeOverrideBlockBeatsAllowUnattended(): void
    {
        $config = $this->createMock(ToolsConfig::class);
        $config->method('defaultOutcome')->willReturn(PolicyOutcome::Approve);
        $config->method('alwaysAllowTools')->willReturn([]);
        $assistant = $this->createMock(AssistantPolicyProviderInterface::class);
        $assistant->method('outcomeFor')->willReturn(null);
        $policy = new ApprovalPolicy($config, new NullGroupPolicyProvider(), $assistant);
        $tool = new ToolDescriptor(
            'custom:helpdesk',
            'Helpdesk',
            '',
            [],
            SideEffect::Write,
            ToolSource::Custom,
            4,
        );

        self::assertSame(
            PolicyOutcome::Auto,
            $policy->decide($tool, 4, PolicyContext::Unattended, null, true),
        );
        self::assertSame(
            PolicyOutcome::Block,
            $policy->decide($tool, 4, PolicyContext::Unattended, null, true, null, PolicyOutcome::Block),
        );
    }

    /**
     * @param callable(TaskNode, NodeContext): NodeResult $fn
     */
    private function runner(callable $fn): TaskRunner
    {
        return new class($fn) implements TaskRunner {
            /** @param callable(TaskNode, NodeContext): NodeResult $fn */
            public function __construct(private $fn)
            {
            }

            public function supportedCapabilities(): array
            {
                return Capability::cases();
            }

            public function describe(): array
            {
                return [];
            }

            public function run(TaskNode $node, NodeContext $context): NodeResult
            {
                return ($this->fn)($node, $context);
            }
        };
    }

    private function executor(TaskRunner $runner): DagExecutor
    {
        $config = $this->createMock(MultitaskRoutingConfig::class);
        $config->method('isParallelEnabled')->willReturn(false);
        $config->method('maxParallel')->willReturn(3);
        $config->method('nodeTimeoutSeconds')->willReturn(120);
        $dispatcher = new class implements MediaNodeDispatcher {
            public function dispatch(MediaNodeRequest $request): MediaNodeJob
            {
                return new SettledMediaNodeJob(NodeResult::ok(null));
            }
        };

        return new DagExecutor(
            new RunnerRegistry([$runner]),
            new ResultAssembler(),
            $dispatcher,
            $config,
            $this->createMock(LoggerInterface::class),
        );
    }

    /** @param array<string, mixed> $options */
    private function context(array $options = []): NodeContext
    {
        $message = $this->createMock(Message::class);
        $message->method('getText')->willReturn('Monday digest');
        $message->method('getFileText')->willReturn('');
        $message->method('getFile')->willReturn(0);
        $message->method('getFilePath')->willReturn('');
        $message->method('getFiles')->willReturn(new ArrayCollection());

        return new NodeContext($message, [], 4, array_merge(['language' => 'en', 'allow_unattended' => false], $options));
    }
}
