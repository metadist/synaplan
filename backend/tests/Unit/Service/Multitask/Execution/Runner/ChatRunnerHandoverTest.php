<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\Knowledge\KnowledgeContextFormatter;
use App\Service\ModelConfigService;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\NodeResult;
use App\Service\Multitask\Execution\Runner\ChatRunner;
use App\Service\Multitask\Execution\UpstreamHandover;
use App\Service\Multitask\Plan\Capability;
use App\Service\Multitask\Plan\TaskNode;
use App\Service\PromptService;
use App\Service\RAG\VectorSearchService;
use Doctrine\Common\Collections\ArrayCollection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The answering node of a plan must see the output of the data nodes it
 * depends on, however the planner wired (or forgot to wire) the reference.
 * Reported against a connected MCP storage server: both tool calls succeeded,
 * the final answer said no results had been provided.
 */
final class ChatRunnerHandoverTest extends TestCase
{
    private const HEAD = "Bucket 'synaplan-demo-archive' exists and is accessible.";
    private const LOCATION = '{"locationConstraint":"eu-central-003"}';

    private function message(string $text): Message&MockObject
    {
        $m = $this->createMock(Message::class);
        $m->method('getText')->willReturn($text);
        $m->method('getFileText')->willReturn('');
        $m->method('getLanguage')->willReturn('en');
        $m->method('getUserId')->willReturn(1);
        $m->method('getChatId')->willReturn(null);
        $m->method('getFile')->willReturn(0);
        $m->method('getFilePath')->willReturn('');
        $m->method('getFiles')->willReturn(new ArrayCollection());

        return $m;
    }

    /** Context after two successful mcp_fetch nodes, as McpFetchRunner records them. */
    private function contextWithToolResults(string $userText): NodeContext
    {
        $ctx = new NodeContext($this->message($userText), [], 1, ['language' => 'en']);
        $ctx->setResult('n1', NodeResult::ok(self::HEAD, [], ['query' => 'Backblaze B2 · s3_head_bucket']));
        $ctx->setResult('n2', NodeResult::ok(self::LOCATION, [], ['query' => 'Backblaze B2 · s3_get_bucket_location']));

        return $ctx;
    }

    /**
     * @param array<int, array{role: string, content: string}>|null $captured
     */
    private function runner(?array &$captured, ?LoggerInterface $logger = null): ChatRunner
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $aiFacade->method('chatStream')->willReturnCallback(function (array $messages, callable $cb) use (&$captured): array {
            $captured = $messages;
            $cb('ANSWER');

            return ['provider' => 'anthropic', 'model' => 'claude-sonnet-5'];
        });
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn(249);
        $modelConfig->method('getProviderForModel')->willReturn('anthropic');
        $modelConfig->method('getModelName')->willReturn('claude-sonnet-5');

        return new ChatRunner(
            $aiFacade,
            $modelConfig,
            $this->createMock(VectorSearchService::class),
            new KnowledgeContextFormatter(),
            $this->createMock(PromptService::class),
            $logger ?? $this->createMock(LoggerInterface::class),
        );
    }

    public function testCorrectlyWiredPlanIsLeftUntouched(): void
    {
        $captured = null;
        $node = new TaskNode('n3', Capability::Chat, ['n1', 'n2'], [
            'text' => "Report reachability and region based on:\nHead: \$n1.text\nRegion: \$n2.text",
        ]);

        $this->runner($captured)->run($node, $this->contextWithToolResults('is my bucket reachable?'));

        self::assertSame(
            "Report reachability and region based on:\nHead: ".self::HEAD."\nRegion: ".self::LOCATION,
            $captured[1]['content'],
        );
        self::assertStringNotContainsString('Data returned by the previous steps', $captured[1]['content']);
    }

    /**
     * The reported shape: the chat node depends on the data nodes but its
     * prompt is the original user message — the model only ever saw the
     * question.
     */
    public function testUpstreamOutputIsAppendedWhenThePlanForgotToSpliceIt(): void
    {
        $captured = null;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('info')->with(
            self::stringContains('did not hand over upstream step output'),
            self::callback(static fn (array $ctx): bool => ['n1 · Backblaze B2 · s3_head_bucket', 'n2 · Backblaze B2 · s3_get_bucket_location'] === $ctx['appended']),
        );
        $node = new TaskNode('n3', Capability::Chat, ['n1', 'n2'], ['text' => '$message.text']);

        $result = $this->runner($captured, $logger)->run($node, $this->contextWithToolResults('Check whether my bucket is reachable and where it lives.'));

        self::assertTrue($result->isSuccessful());
        $user = $captured[1]['content'];
        self::assertStringStartsWith('Check whether my bucket is reachable and where it lives.', $user);
        self::assertStringContainsString('Data returned by the previous steps', $user);
        self::assertStringContainsString("[n1 · Backblaze B2 · s3_head_bucket]\n".self::HEAD, $user);
        self::assertStringContainsString("[n2 · Backblaze B2 · s3_get_bucket_location]\n".self::LOCATION, $user);
        // The system prompt is not the carrier — retrieval queries stay clean.
        self::assertStringNotContainsString(self::HEAD, $captured[0]['content']);
    }

    public function testDataParkedUnderAnotherInputKeyReachesTheModelOnce(): void
    {
        $captured = null;
        $node = new TaskNode('n3', Capability::Chat, ['n1', 'n2'], [
            'text' => 'Summarize the read-only results.',
            'context' => ['$n1.text', '$n2.text'],
        ]);

        $this->runner($captured)->run($node, $this->contextWithToolResults('check my bucket'));

        $user = $captured[1]['content'];
        self::assertSame(1, substr_count($user, self::HEAD), 'head-bucket output must appear exactly once');
        self::assertSame(1, substr_count($user, self::LOCATION), 'location output must appear exactly once');
    }

    public function testMisspelledReferenceStillHandsOverTheData(): void
    {
        $captured = null;
        $node = new TaskNode('n3', Capability::Chat, ['n1', 'n2'], [
            'text' => "Report:\nHead: \$n1.output\nRegion: \${n2.text}",
        ]);

        $this->runner($captured)->run($node, $this->contextWithToolResults('check my bucket'));

        self::assertSame("Report:\nHead: ".self::HEAD."\nRegion: ".self::LOCATION, $captured[1]['content']);
    }

    public function testMissingSkipsFailedEmptyAndAlreadyPresentUpstreamText(): void
    {
        $ctx = new NodeContext($this->message('q'), [], 1, []);
        $ctx->setResult('n1', NodeResult::failed('could not reach the data source'));
        $ctx->setResult('n2', NodeResult::ok('', [['path' => 'a.png', 'type' => 'image']]));
        $ctx->setResult('n3', NodeResult::ok('already there'));
        $ctx->setResult('n4', NodeResult::ok('fresh data'));
        $node = new TaskNode('n5', Capability::Chat, ['n1', 'n2', 'n3', 'n4'], ['text' => 'Use: already there']);

        $missing = UpstreamHandover::missing($node, $ctx, 'Use: already there', ['text' => 'Use: already there']);

        self::assertSame(['n4' => 'fresh data'], $missing);
        self::assertSame('', UpstreamHandover::render([]));
    }

    public function testReportableFailureIsAppendedEvenWhenTheReferenceWasWired(): void
    {
        $captured = null;
        $ctx = new NodeContext($this->message('is the bucket reachable?'), [], 1, ['language' => 'en']);
        $ctx->setResult('n1', NodeResult::reportableFailure(
            'Backblaze reported an error: NotFound',
            ['query' => 'Backblaze · s3_head_bucket'],
        ));
        $node = new TaskNode('n2', Capability::Chat, ['n1'], [
            'text' => "Answer based on:\n\$n1.text",
        ]);

        $this->runner($captured)->run($node, $ctx);

        $user = $captured[1]['content'];
        self::assertStringContainsString('[n1 · Backblaze · s3_head_bucket · FAILED]', $user);
        self::assertStringContainsString('Backblaze reported an error: NotFound', $user);
        self::assertStringContainsString('A step marked FAILED was attempted', $user);
        self::assertStringContainsString('Do not claim that no connection is configured', $user);
    }

    public function testHardFailureIsNotAppended(): void
    {
        $captured = null;
        $ctx = new NodeContext($this->message('q'), [], 1, ['language' => 'en']);
        $ctx->setResult('n1', NodeResult::failed('the tool does not exist'));
        $node = new TaskNode('n2', Capability::Chat, ['n1'], ['text' => 'Answer the question.']);

        $this->runner($captured)->run($node, $ctx);

        self::assertStringNotContainsString('FAILED', $captured[1]['content']);
        self::assertStringNotContainsString('does not exist', $captured[1]['content']);
    }

    public function testLiteralExtraInputsAreNotMistakenForData(): void
    {
        $ctx = $this->contextWithToolResults('q');
        $node = new TaskNode('n3', Capability::Chat, [], ['text' => 'Answer in German.', 'language' => 'de']);

        self::assertSame([], UpstreamHandover::missing($node, $ctx, 'Answer in German.', ['text' => 'Answer in German.', 'language' => 'de']));
    }
}
