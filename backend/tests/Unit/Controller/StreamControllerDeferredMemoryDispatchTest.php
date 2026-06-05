<?php

declare(strict_types=1);

namespace App\Tests\Unit\Controller;

use App\AI\Service\AiFacade;
use App\Controller\StreamController;
use App\Message\ExtractMemoriesCommand;
use App\Service\File\UserUploadPathBuilder;
use App\Service\GuestSessionService;
use App\Service\MemoryExtractionDispatcher;
use App\Service\Message\MessageForwardingService;
use App\Service\Message\MessageProcessor;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\RateLimitService;
use App\Service\WidgetService;
use App\Service\WidgetSessionService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit coverage for {@see StreamController::dispatchDeferredMemoryExtraction()}.
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
 * so this test only needs to pin the controller's contribution: pulling
 * the payload out of the metadata array and forwarding a typed command.
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
final class StreamControllerDeferredMemoryDispatchTest extends TestCase
{
    private MemoryExtractionDispatcher&MockObject $memoryExtractionDispatcher;
    private StreamController $controller;

    protected function setUp(): void
    {
        $this->memoryExtractionDispatcher = $this->createMock(MemoryExtractionDispatcher::class);

        $this->controller = new StreamController(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(MessageProcessor::class),
            new NullLogger(),
            $this->createMock(ModelConfigService::class),
            $this->createMock(WidgetService::class),
            $this->createMock(WidgetSessionService::class),
            $this->createMock(GuestSessionService::class),
            $this->createMock(RateLimitService::class),
            '/tmp/upload',
            $this->createMock(UserUploadPathBuilder::class),
            $this->createMock(PromptService::class),
            $this->createMock(MessageForwardingService::class),
            $this->memoryExtractionDispatcher,
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

    /**
     * @param array<string, mixed> $metadata
     */
    private function invokeDispatch(array $metadata): void
    {
        $reflection = new \ReflectionMethod(StreamController::class, 'dispatchDeferredMemoryExtraction');
        $reflection->invoke($this->controller, $metadata);
    }
}
