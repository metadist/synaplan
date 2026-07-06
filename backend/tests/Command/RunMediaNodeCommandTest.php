<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Command\RunMediaNodeCommand;
use App\Entity\Message;
use App\Repository\MessageRepository;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Parallel\ProcessMediaNodeJob;
use App\Service\Multitask\Execution\RunnerRegistry;
use App\Service\Multitask\Execution\TaskRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class RunMediaNodeCommandTest extends TestCase
{
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
                return [Capability::ImageGeneration, Capability::Text2Sound];
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

    private function tester(TaskRunner $runner, ?MessageRepository $messages = null): CommandTester
    {
        $command = new RunMediaNodeCommand(
            new RunnerRegistry([$runner]),
            $messages ?? $this->createMock(MessageRepository::class),
        );

        $application = new Application();
        $application->addCommand($command);

        return new CommandTester($application->find('app:multitask:run-media-node'));
    }

    /**
     * @return array<string, mixed>
     */
    private function parseResult(CommandTester $tester): array
    {
        foreach (explode("\n", $tester->getDisplay()) as $line) {
            $line = trim($line);
            if (str_starts_with($line, ProcessMediaNodeJob::RESULT_MARKER)) {
                $decoded = json_decode(substr($line, \strlen(ProcessMediaNodeJob::RESULT_MARKER)), true);
                self::assertIsArray($decoded);

                return $decoded;
            }
        }

        self::fail('no result marker line emitted');
    }

    public function testLoadsRealMessageAndPassesThreadAndOptions(): void
    {
        $real = $this->createMock(Message::class);
        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::once())->method('find')->with(4711)->willReturn($real);

        $seenContext = null;
        $runner = $this->runner(function (TaskNode $node, NodeContext $context) use (&$seenContext): NodeResult {
            $seenContext = $context;

            return NodeResult::ok('done', [['path' => '/api/v1/files/uploads/a.png', 'type' => 'image']]);
        });

        $tester = $this->tester($runner, $messages);
        $tester->execute(['--payload' => json_encode([
            'node_id' => 'n1',
            'capability' => 'image_generation',
            'prompt' => 'a dog with a hat',
            'user_id' => 7,
            'language' => 'de',
            'params' => [],
            'message_id' => 4711,
            'thread' => [['role' => 'user', 'content' => 'draw my photo'], ['role' => 'assistant', 'content' => 'sure']],
            'options' => ['quality' => 'high'],
        ])]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $result = $this->parseResult($tester);
        self::assertTrue($result['ok']);
        self::assertSame('done', $result['text']);

        // The runner must see the REAL message (attachments for pic2pic) plus
        // the thread snapshot and options forwarded by the dispatcher.
        self::assertInstanceOf(NodeContext::class, $seenContext);
        self::assertSame($real, $seenContext->message);
        self::assertSame(7, $seenContext->userId);
        self::assertSame('de', $seenContext->classification['language']);
        self::assertCount(2, $seenContext->thread);
        self::assertSame(['quality' => 'high'], $seenContext->options);
    }

    public function testFallsBackToSyntheticMessageWhenRowIsGone(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->method('find')->willReturn(null);

        $seenContext = null;
        $runner = $this->runner(function (TaskNode $node, NodeContext $context) use (&$seenContext): NodeResult {
            $seenContext = $context;

            return NodeResult::ok(null, [['path' => '/x.mp3', 'type' => 'audio']]);
        });

        $tester = $this->tester($runner, $messages);
        $tester->execute(['--payload' => json_encode([
            'capability' => 'text2sound',
            'prompt' => 'read this aloud',
            'user_id' => 3,
            'language' => 'en',
            'message_id' => 999,
        ])]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->parseResult($tester)['ok']);
        self::assertInstanceOf(NodeContext::class, $seenContext);
        self::assertSame('read this aloud', $seenContext->message->getText());
        self::assertSame(3, $seenContext->message->getUserId());
    }

    public function testRejectsUnknownCapability(): void
    {
        $tester = $this->tester($this->runner(static fn (): NodeResult => NodeResult::ok('x')));
        $tester->execute(['--payload' => json_encode(['capability' => 'time_travel', 'prompt' => 'p'])]);

        $result = $this->parseResult($tester);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('time_travel', $result['error']);
    }

    public function testRejectsMissingPayload(): void
    {
        $tester = $this->tester($this->runner(static fn (): NodeResult => NodeResult::ok('x')));
        $tester->execute([]);

        $result = $this->parseResult($tester);
        self::assertFalse($result['ok']);
        self::assertStringContainsString('payload', $result['error']);
    }

    public function testRunnerFailureIsReportedNotThrown(): void
    {
        $runner = $this->runner(static function (): NodeResult {
            throw new \RuntimeException('provider exploded');
        });

        $tester = $this->tester($runner);
        $tester->execute(['--payload' => json_encode(['capability' => 'image_generation', 'prompt' => 'p'])]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        $result = $this->parseResult($tester);
        self::assertFalse($result['ok']);
        self::assertSame('provider exploded', $result['error']);
    }
}
