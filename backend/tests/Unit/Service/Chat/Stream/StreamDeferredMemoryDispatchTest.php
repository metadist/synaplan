<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Controller\StreamController;
use App\Entity\User;
use App\Message\ExtractMemoriesCommand;
use App\Service\Chat\ChatTitleService;
use App\Service\Chat\ConversationSummaryRefreshDispatcher;
use App\Service\Chat\Stream\StreamCaller;
use App\Service\Chat\Stream\StreamTurnFinisher;
use App\Service\Chat\Stream\StreamTurnOptions;
use App\Service\Chat\Stream\StreamTurnRequest;
use App\Service\Chat\Stream\StreamUserInput;
use App\Service\Memory\MemoryExtractionDispatcher;
use App\Service\Message\MessageForwardingService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for {@see StreamTurnFinisher::afterReply()}.
 *
 * Issue #881: ChatHandler now hands the prepared
 * {@see ExtractMemoriesCommand} back to the StreamController in
 * `metadata.extraction_payload` instead of dispatching it itself. This
 * lets the controller fire the dispatch AFTER it has flushed the
 * outgoing assistant message — without that ordering the messenger
 * worker can race the OUT-row insert and write the
 * `extracted_memories` meta to the IN row only, leaving the frontend
 * polling the OUT id forever (no toast in production).
 *
 * The Copilot review of PR #939 flagged that the dispatch + log +
 * swallow contract used to be duplicated between this controller and
 * ChatHandler. Both now go through {@see MemoryExtractionDispatcher},
 * so this test only needs to pin the stream's contribution: pulling
 * the payload out of the metadata array and forwarding a typed command,
 * after the reply is stored and never for an incognito turn.
 *
 * The behaviour pinned here:
 *   - Forwards the typed payload to the dispatcher exactly once.
 *   - No-op when the payload is missing or has the wrong type (the
 *     skip path inside ChatHandler returns null for widgets / disabled
 *     users, and we must not invent a dispatch out of thin air).
 *
 * Bus-failure swallowing is covered by
 * {@see \App\Tests\Unit\Service\MemoryExtractionDispatcherTest}.
 */
final class StreamDeferredMemoryDispatchTest extends TestCase
{
    private MemoryExtractionDispatcher&MockObject $memoryExtractionDispatcher;
    private StreamTurnFinisher $finisher;

    protected function setUp(): void
    {
        $this->memoryExtractionDispatcher = $this->createMock(MemoryExtractionDispatcher::class);

        $this->finisher = new StreamTurnFinisher(
            $this->createStub(EntityManagerInterface::class),
            $this->memoryExtractionDispatcher,
            $this->createStub(ConversationSummaryRefreshDispatcher::class),
            $this->createStub(MessageForwardingService::class),
            $this->createStub(ChatTitleService::class),
        );
    }

    public function testDispatchesExtractionPayloadOnce(): void
    {
        $command = new ExtractMemoriesCommand(
            messageId: 99,
            userId: 7,
            aiResponse: 'reply text',
            threadSnapshot: [['role' => 'user', 'content' => 'hi']],
            relevantMemories: [],
        );

        $this->memoryExtractionDispatcher
            ->expects($this->once())
            ->method('dispatch')
            ->with($this->identicalTo($command));

        $this->invokeDispatch(['extraction_payload' => $command]);
    }

    public function testIgnoresMissingPayload(): void
    {
        $this->memoryExtractionDispatcher->expects($this->never())->method('dispatch');

        $this->invokeDispatch([]);
    }

    public function testIgnoresNullPayload(): void
    {
        $this->memoryExtractionDispatcher->expects($this->never())->method('dispatch');

        $this->invokeDispatch(['extraction_payload' => null]);
    }

    public function testIgnoresWrongTypePayload(): void
    {
        $this->memoryExtractionDispatcher->expects($this->never())->method('dispatch');

        // Defensive: if a future regression starts shipping a serialized
        // array here, we must not blindly forward it to the bus.
        $this->invokeDispatch(['extraction_payload' => ['fake' => 'payload']]);
    }

    public function testIncognitoNeverDispatches(): void
    {
        $command = new ExtractMemoriesCommand(
            messageId: 99,
            userId: 7,
            aiResponse: 'reply text',
            threadSnapshot: [],
            relevantMemories: [],
        );
        $this->memoryExtractionDispatcher->expects($this->never())->method('dispatch');

        $this->finisher->afterReply($this->turn(true), null, ['extraction_payload' => $command], 'reply text');
    }

    private function turn(bool $incognito): StreamTurnRequest
    {
        return new StreamTurnRequest(
            caller: new StreamCaller(new User(), 'en'),
            chatId: $incognito ? null : '7',
            trackId: '4242',
            input: new StreamUserInput('Hello'),
            options: new StreamTurnOptions(),
            incognito: $incognito,
        );
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function invokeDispatch(array $metadata): void
    {
        $this->finisher->afterReply($this->turn(false), null, $metadata, 'reply text');
    }
}
