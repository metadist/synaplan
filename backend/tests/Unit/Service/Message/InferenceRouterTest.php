<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Handler\MessageHandlerInterface;
use App\Service\Message\InferenceRouter;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class InferenceRouterTest extends TestCase
{
    /**
     * The regression test for the `document_generation` gap the plan calls
     * out: the intent existed but InferenceRouter::getHandler() had no entry
     * for it, silently defaulting to 'chat' via `?? 'chat'`. Now that the
     * mapping is explicit (via SystemCapabilityRegistry), this must still
     * route to the 'chat' handler — the fix does not change behaviour, it
     * makes the existing behaviour an explicit decision.
     */
    public function testDocumentGenerationIntentRoutesToTheChatHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::once())
            ->method('handle')
            ->willReturn(['content' => 'ok', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
        );

        $result = $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'document_generation', 'topic' => 'officemaker'],
        );

        self::assertSame(['content' => 'ok', 'metadata' => []], $result);
    }

    public function testImageGenerationIntentRoutesToTheImageGenerationHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $imageHandler = $this->createHandlerMock('image_generation');
        $imageHandler->expects(self::once())
            ->method('handle')
            ->willReturn(['content' => 'image', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler, $imageHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'image_generation', 'topic' => 'mediamaker'],
        );
    }

    public function testUnknownIntentFallsBackToTheChatHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::once())
            ->method('handle')
            ->willReturn(['content' => 'fallback', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'some_unregistered_intent'],
        );
    }

    private function createHandlerMock(string $name): MessageHandlerInterface&MockObject
    {
        $handler = $this->createMock(MessageHandlerInterface::class);
        $handler->method('getName')->willReturn($name);

        return $handler;
    }
}
