<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\Message;
use App\Entity\User;
use App\Service\Chat\Stream\ChatStreamOrchestrator;
use App\Service\Chat\Stream\NonStreamingTurn;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamCompletion;
use App\Service\Chat\Stream\StreamFailureReporter;
use App\Service\Chat\Stream\StreamModelRouting;
use App\Service\Chat\Stream\StreamRelayFactory;
use App\Service\Chat\Stream\StreamReplyPersister;
use App\Service\Chat\Stream\StreamTurnOptions;
use App\Service\Chat\Stream\StreamTurnPreparer;
use App\Service\Chat\Stream\StreamTurnRequest;
use App\Service\Chat\Stream\StreamTurnState;
use App\Service\Chat\Stream\StreamUserInput;
use App\Service\Exception\StreamCancelledException;
use App\Service\Media\MediaCancellationStore;
use App\Service\Message\MessageProcessor;
use App\Service\Realtime\SseWriter;
use App\Tests\Support\ChatRunServiceFactory;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * How a streamed turn ends: every failure reaches the client, an explicit
 * Stop does not count as one, and a person over a limit only gets the
 * report.
 *
 * The turns here have no resumable run (incognito, or Redis down), which is
 * exactly when a failure was once swallowed: a nullsafe `finish()` call
 * skipped the reporter passed as its argument.
 */
#[AllowMockObjectsWithoutExpectations]
final class ChatStreamOrchestratorTest extends TestCase
{
    private MessageProcessor&MockObject $processor;
    private StreamTurnPreparer&MockObject $preparer;
    private StreamFailureReporter&MockObject $failures;

    protected function setUp(): void
    {
        $this->processor = $this->createMock(MessageProcessor::class);
        $this->preparer = $this->createMock(StreamTurnPreparer::class);
        $this->preparer->method('prepare')->willReturnCallback(static function (SseWriter $sse, StreamTurnRequest $turn, StreamTurnState $state): bool {
            $state->incoming = new Message();

            return true;
        });
        $this->preparer->method('processingOptions')->willReturn([]);
        $this->failures = $this->createMock(StreamFailureReporter::class);
    }

    public function testAnExceptionIsReportedWithoutAResumableRun(): void
    {
        $this->processor->method('processStream')->willThrowException(new \RuntimeException('provider exploded'));
        $this->failures->expects(self::once())
            ->method('reportException')
            ->with(self::anything(), self::anything(), self::anything(), self::isInstanceOf(\RuntimeException::class))
            ->willReturn(null);

        $this->stream($this->turn(incognito: true));
    }

    public function testAnExplicitStopIsNotReportedAsAFailure(): void
    {
        $this->processor->method('processStream')->willThrowException(new StreamCancelledException('Stream cancelled by user'));
        $this->failures->expects(self::never())->method('reportException');

        $this->stream($this->turn(incognito: true));
    }

    public function testAFailedResultIsReportedAndACancelledOneIsNot(): void
    {
        $failed = ['success' => false, 'error' => 'AI provider failed for streaming'];
        $cancelled = ['success' => false, 'cancelled' => true, 'error' => 'Stream cancelled by user'];
        $this->processor->method('processStream')->willReturnOnConsecutiveCalls($failed, $cancelled);
        $this->failures->method('isCancellation')->willReturnCallback(static fn (array $result): bool => true === ($result['cancelled'] ?? false));
        $this->failures->expects(self::once())->method('reportFailedResult')->with(self::anything(), self::anything(), self::anything(), $failed);

        $this->stream($this->turn(incognito: true));
        $this->stream($this->turn(incognito: true));
    }

    public function testAPersonOverALimitOnlyGetsTheReport(): void
    {
        $this->preparer->expects(self::never())->method('prepare');
        $this->processor->expects(self::never())->method('processStream');

        $frames = $this->stream($this->turn(incognito: false, rateLimitError: ['status' => 'error', 'error' => 'Rate limit exceeded']));

        self::assertSame([['status' => 'error', 'error' => 'Rate limit exceeded']], $frames);
    }

    /**
     * @param array<string, mixed>|null $rateLimitError
     */
    private function turn(bool $incognito, ?array $rateLimitError = null): StreamTurnRequest
    {
        return new StreamTurnRequest(
            caller: new StreamCaller(new User(), 'en'),
            chatId: $incognito ? null : '3',
            trackId: '4242',
            input: new StreamUserInput('Hello'),
            options: new StreamTurnOptions(),
            incognito: $incognito,
            rateLimitError: $rateLimitError,
        );
    }

    /**
     * @return list<array<string, mixed>> the SSE frames the turn wrote
     */
    private function stream(StreamTurnRequest $turn): array
    {
        $routing = $this->createStub(StreamModelRouting::class);
        $routing->method('intendedChat')->willReturn(['id' => null, 'name' => null, 'provider' => null]);
        $routing->method('supportsStreaming')->willReturn(true);

        $orchestrator = new ChatStreamOrchestrator(
            $this->processor,
            ChatRunServiceFactory::withoutRedis(),
            $this->preparer,
            $routing,
            $this->createStub(NonStreamingTurn::class),
            new StreamRelayFactory($this->createStub(MediaCancellationStore::class)),
            $this->createStub(StreamReplyPersister::class),
            $this->createStub(StreamCompletion::class),
            $this->failures,
            new NullLogger(),
        );

        ob_start();
        try {
            $orchestrator->run($turn, new SseWriter(captureMode: true));
        } finally {
            $raw = (string) ob_get_clean();
        }

        $frames = [];
        foreach (preg_split("/\n\n+/", trim($raw)) ?: [] as $frame) {
            if (str_starts_with($frame, 'data: ')) {
                $frames[] = json_decode(substr($frame, 6), true);
            }
        }

        return $frames;
    }
}
