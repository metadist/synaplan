<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\Message;
use App\Service\Multitask\Execution\DagExecutor;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\ResultAssembler;
use App\Service\Multitask\Execution\RunnerRegistry;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\Multitask\Plan\TaskPlan;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DagExecutorTest extends TestCase
{
    /**
     * A configurable runner: supports all capabilities, delegates to a closure.
     *
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

            public function run(TaskNode $node, NodeContext $context): NodeResult
            {
                return ($this->fn)($node, $context);
            }
        };
    }

    private function executor(TaskRunner $runner): DagExecutor
    {
        return new DagExecutor(
            new RunnerRegistry([$runner]),
            new ResultAssembler(),
            $this->createMock(LoggerInterface::class),
        );
    }

    private function context(): NodeContext
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn('Create an image of a dog and summarize the scene as an mp3');
        $m->method('getFileText')->willReturn('');
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return new NodeContext($m, [], 1, ['language' => 'en']);
    }

    public function testCanonicalDocToMp3Chain(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text', 'inputs' => ['files' => '$message.files']],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1'], 'inputs' => ['text' => '$n1.text']],
                ['id' => 'n3', 'capability' => 'text2sound', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n2', 'n3'], 'inputs' => ['text' => '$n2.text', 'attachments' => ['$n3.file']]],
            ],
        ]);

        $runner = $this->runner(function (TaskNode $node, NodeContext $ctx): NodeResult {
            $in = $ctx->resolveInputs($node);

            return match ($node->capability) {
                Capability::ExtractText => NodeResult::ok('DOC BODY'),
                Capability::Summarize => NodeResult::ok('SUMMARY of '.$in['text']),
                Capability::Text2Sound => NodeResult::ok(null, [['path' => '/api/v1/files/uploads/x.mp3', 'type' => 'audio']]),
                Capability::ComposeReply => NodeResult::ok($in['text'], $in['attachments']),
                default => NodeResult::failed('unexpected'),
            };
        });

        $result = $this->executor($runner)->execute($plan, $this->context());

        self::assertFalse($result['partial_failure']);
        self::assertFalse($result['all_failed']);
        self::assertSame('SUMMARY of DOC BODY', $result['content']);
        self::assertCount(1, $result['files']);
        self::assertSame('audio', $result['files'][0]['type']);
        self::assertSame(
            ['n1' => 'done', 'n2' => 'done', 'n3' => 'done', 'n4' => 'done'],
            $result['node_statuses'],
        );
    }

    public function testFailureIsolationSkipsDependentsButRunsIndependentBranch(): void
    {
        // n1 fails -> n2 (depends n1) skipped; n3 (independent) still runs; reply = n3.
        $plan = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'extract_text'],
                ['id' => 'n2', 'capability' => 'summarize', 'depends_on' => ['n1']],
                ['id' => 'n3', 'capability' => 'chat'],
            ],
        ]);

        $runner = $this->runner(fn (TaskNode $node): NodeResult => match ($node->capability) {
            Capability::ExtractText => NodeResult::failed('tika down'),
            Capability::Chat => NodeResult::ok('independent answer'),
            default => NodeResult::failed('should not run'),
        });

        $result = $this->executor($runner)->execute($plan, $this->context());

        self::assertSame('failed', $result['node_statuses']['n1']);
        self::assertSame('skipped', $result['node_statuses']['n2']);
        self::assertSame('done', $result['node_statuses']['n3']);
        self::assertTrue($result['partial_failure']);
        self::assertFalse($result['all_failed']);
        self::assertSame('independent answer', $result['content']);
    }

    public function testMissingRunnerMarksNodeFailed(): void
    {
        $plan = TaskPlan::singleChatPlan('en');
        $executor = new DagExecutor(
            new RunnerRegistry([]), // no runners registered
            new ResultAssembler(),
            $this->createMock(LoggerInterface::class),
        );

        $result = $executor->execute($plan, $this->context());

        self::assertTrue($result['all_failed']);
        self::assertSame('failed', $result['node_statuses']['n1']);
    }

    public function testRunnerThrowIsIsolated(): void
    {
        $plan = TaskPlan::singleChatPlan('en');
        $runner = $this->runner(function (): NodeResult {
            throw new \RuntimeException('boom');
        });

        $result = $this->executor($runner)->execute($plan, $this->context());

        self::assertTrue($result['all_failed']);
        self::assertSame('failed', $result['node_statuses']['n1']);
        // Best-effort content rather than a crash.
        self::assertNotSame('', $result['content']);
    }

    public function testProgressCallbackEmitsPerNodeStateUpdates(): void
    {
        $plan = TaskPlan::singleChatPlan('en');
        $runner = $this->runner(fn (): NodeResult => NodeResult::ok('hi'));

        $states = [];
        $this->executor($runner)->execute($plan, $this->context(), function (array $e) use (&$states): void {
            if ('task_update' === $e['status']) {
                $states[] = $e['metadata']['state'];
            }
        });

        self::assertContains('running', $states);
        self::assertContains('done', $states);
    }

    public function testProgressCallbackEmitsTaskFileForMediaNode(): void
    {
        $plan = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n1',
            'tasks' => [['id' => 'n1', 'capability' => 'text2sound']],
        ]);
        $runner = $this->runner(fn (): NodeResult => NodeResult::ok(null, [['path' => '/api/v1/files/uploads/x.mp3', 'type' => 'audio']]));

        $fileEvents = [];
        $this->executor($runner)->execute($plan, $this->context(), function (array $e) use (&$fileEvents): void {
            if ('task_file' === $e['status']) {
                $fileEvents[] = $e['metadata'];
            }
        });

        self::assertCount(1, $fileEvents);
        self::assertSame('audio', $fileEvents[0]['type']);
        self::assertSame('n1', $fileEvents[0]['node_id']);
    }

    public function testStreamingChunkSinkTagsCurrentNode(): void
    {
        $plan = TaskPlan::singleChatPlan('en');
        // A runner that streams two chunks via the context sink.
        $runner = $this->runner(function ($node, NodeContext $ctx): NodeResult {
            $ctx->streamChunk('Hel');
            $ctx->streamChunk('lo');

            return NodeResult::ok('Hello');
        });

        $chunks = [];
        $this->executor($runner)->execute($plan, $this->context(), function (array $e) use (&$chunks): void {
            if ('task_chunk' === $e['status']) {
                $chunks[] = $e['metadata'];
            }
        });

        self::assertSame([['node_id' => 'n1', 'chunk' => 'Hel'], ['node_id' => 'n1', 'chunk' => 'lo']], $chunks);
    }
}
