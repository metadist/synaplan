<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution;

use App\Entity\Message;
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

    /**
     * @param callable(MediaNodeRequest): NodeResult|null $dispatchFn
     */
    private function dispatcher(?callable $dispatchFn = null): MediaNodeDispatcher
    {
        return new class($dispatchFn) implements MediaNodeDispatcher {
            /** @param callable(MediaNodeRequest): NodeResult|null $fn */
            public function __construct(private $fn)
            {
            }

            public function dispatch(MediaNodeRequest $request): MediaNodeJob
            {
                $result = null !== $this->fn
                    ? ($this->fn)($request)
                    : NodeResult::ok(null, [['path' => '/api/v1/files/uploads/'.$request->nodeId.'.png', 'type' => 'image']]);

                return new SettledMediaNodeJob($result);
            }
        };
    }

    private function config(bool $parallel, int $cap = 3, int $timeout = 120): MultitaskRoutingConfig
    {
        $config = $this->createMock(MultitaskRoutingConfig::class);
        $config->method('isParallelEnabled')->willReturn($parallel);
        $config->method('maxParallel')->willReturn($cap);
        $config->method('nodeTimeoutSeconds')->willReturn($timeout);

        return $config;
    }

    private function executor(TaskRunner $runner, bool $parallel = false, ?MediaNodeDispatcher $dispatcher = null, int $cap = 3): DagExecutor
    {
        return new DagExecutor(
            new RunnerRegistry([$runner]),
            new ResultAssembler(),
            $dispatcher ?? $this->dispatcher(),
            $this->config($parallel, $cap),
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
            $this->dispatcher(),
            $this->config(false),
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

    // ---- Sprint 4: parallel mode ----

    private function dogMp3Plan(): TaskPlan
    {
        return TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n4',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a dog']],
                ['id' => 'n2', 'capability' => 'summarize', 'inputs' => ['text' => '$message.text']],
                ['id' => 'n3', 'capability' => 'text2sound', 'depends_on' => ['n2'], 'inputs' => ['text' => '$n2.text']],
                ['id' => 'n4', 'capability' => 'compose_reply', 'depends_on' => ['n1', 'n3'], 'inputs' => ['text' => '$n2.text', 'attachments' => ['$n1.file', '$n3.file']]],
            ],
        ]);
    }

    /** Inline runner that handles only the text/compose nodes (media is offloaded). */
    private function textRunner(): TaskRunner
    {
        return $this->runner(function (TaskNode $node, NodeContext $ctx): NodeResult {
            $in = $ctx->resolveInputs($node);

            return match ($node->capability) {
                Capability::Summarize => NodeResult::ok('SUMMARY'),
                Capability::ComposeReply => NodeResult::ok(is_string($in['text']) ? $in['text'] : '', array_values(array_filter((array) ($in['attachments'] ?? []), 'is_array'))),
                default => NodeResult::failed('media must be offloaded, not run inline: '.$node->capability->value),
            };
        });
    }

    public function testParallelOffloadsMediaAndRunsTextInline(): void
    {
        $dispatcher = $this->dispatcher(function (MediaNodeRequest $req): NodeResult {
            $type = 'text2sound' === $req->capability ? 'audio' : 'image';

            return NodeResult::ok(null, [['path' => '/api/v1/files/uploads/'.$req->nodeId.'.'.$type, 'type' => $type]]);
        });

        $result = $this->executor($this->textRunner(), parallel: true, dispatcher: $dispatcher)
            ->execute($this->dogMp3Plan(), $this->context());

        self::assertFalse($result['partial_failure']);
        self::assertSame(['n1' => 'done', 'n2' => 'done', 'n3' => 'done', 'n4' => 'done'], $result['node_statuses']);
        self::assertSame('SUMMARY', $result['content']);
        // Both media files (image from n1, audio from n3) gathered by compose.
        $types = array_map(static fn ($f) => $f['type'], $result['files']);
        sort($types);
        self::assertSame(['audio', 'image'], $types);
    }

    public function testParallelMediaFailureIsIsolated(): void
    {
        // Image node fails; its dependent compose is skipped, but the summary survives.
        $dispatcher = $this->dispatcher(function (MediaNodeRequest $req): NodeResult {
            return 'image_generation' === $req->capability
                ? NodeResult::failed('provider 500')
                : NodeResult::ok(null, [['path' => '/x.mp3', 'type' => 'audio']]);
        });

        $result = $this->executor($this->textRunner(), parallel: true, dispatcher: $dispatcher)
            ->execute($this->dogMp3Plan(), $this->context());

        self::assertSame('failed', $result['node_statuses']['n1']);
        self::assertSame('done', $result['node_statuses']['n2']);
        self::assertSame('skipped', $result['node_statuses']['n4']);
        self::assertTrue($result['partial_failure']);
        self::assertFalse($result['all_failed']);
        self::assertSame('SUMMARY', $result['content']); // best-effort recovery
    }

    public function testParallelRespectsConcurrencyCap(): void
    {
        // Two independent image nodes + cap=1 → never more than 1 in flight.
        $plan = TaskPlan::fromArray([
            'version' => 1, 'language' => 'en', 'reply_node' => 'n3',
            'tasks' => [
                ['id' => 'n1', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'a']],
                ['id' => 'n2', 'capability' => 'image_generation', 'inputs' => ['prompt' => 'b']],
                ['id' => 'n3', 'capability' => 'compose_reply', 'depends_on' => ['n1', 'n2'], 'inputs' => ['attachments' => ['$n1.file', '$n2.file']]],
            ],
        ]);

        $concurrent = 0;
        $maxConcurrent = 0;
        $dispatcher = new class($concurrent, $maxConcurrent) implements MediaNodeDispatcher {
            public function __construct(private int &$concurrent, private int &$max)
            {
            }

            public function dispatch(MediaNodeRequest $request): MediaNodeJob
            {
                ++$this->concurrent;
                $this->max = max($this->max, $this->concurrent);
                $concurrentRef = &$this->concurrent;

                return new class($concurrentRef, $request->nodeId) implements MediaNodeJob {
                    public function __construct(private int &$concurrent, private string $nodeId)
                    {
                    }

                    public function wait(int $timeoutSeconds): NodeResult
                    {
                        --$this->concurrent;

                        return NodeResult::ok(null, [['path' => '/'.$this->nodeId.'.png', 'type' => 'image']]);
                    }
                };
            }
        };

        $result = $this->executor($this->textRunner(), parallel: true, dispatcher: $dispatcher, cap: 1)
            ->execute($plan, $this->context());

        self::assertSame('done', $result['node_statuses']['n1']);
        self::assertSame('done', $result['node_statuses']['n2']);
        self::assertLessThanOrEqual(1, $maxConcurrent, 'cap=1 must never run two media nodes at once');
    }

    public function testParallelAndSequentialProduceSameResult(): void
    {
        $dispatcher = $this->dispatcher(function (MediaNodeRequest $req): NodeResult {
            $type = 'text2sound' === $req->capability ? 'audio' : 'image';

            return NodeResult::ok(null, [['path' => '/'.$req->nodeId.'.'.$type, 'type' => $type]]);
        });

        // Sequential runner must also handle media inline (no dispatch in seq mode).
        $seqRunner = $this->runner(function (TaskNode $node, NodeContext $ctx): NodeResult {
            $in = $ctx->resolveInputs($node);

            return match ($node->capability) {
                Capability::Summarize => NodeResult::ok('SUMMARY'),
                Capability::ImageGeneration => NodeResult::ok(null, [['path' => '/n1.image', 'type' => 'image']]),
                Capability::Text2Sound => NodeResult::ok(null, [['path' => '/n3.audio', 'type' => 'audio']]),
                Capability::ComposeReply => NodeResult::ok(is_string($in['text']) ? $in['text'] : '', array_values(array_filter((array) ($in['attachments'] ?? []), 'is_array'))),
                default => NodeResult::failed('x'),
            };
        });

        $seq = $this->executor($seqRunner, parallel: false)->execute($this->dogMp3Plan(), $this->context());
        $par = $this->executor($this->textRunner(), parallel: true, dispatcher: $dispatcher)->execute($this->dogMp3Plan(), $this->context());

        self::assertSame($seq['node_statuses'], $par['node_statuses']);
        self::assertSame($seq['content'], $par['content']);
        self::assertSame(
            array_map(static fn ($f) => $f['type'], $seq['files']),
            array_map(static fn ($f) => $f['type'], $par['files']),
        );
    }
}
