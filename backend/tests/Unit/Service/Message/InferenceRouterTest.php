<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Handler\MessageHandlerInterface;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\Routing\RoutingDirective;
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
            $this->createMock(MessageClassifier::class),
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
            $this->createMock(MessageClassifier::class),
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
            $this->createMock(MessageClassifier::class),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'some_unregistered_intent'],
        );
    }

    public function testAHandoffDirectiveReRoutesToTheHandedOffCapability(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::once())
            ->method('handle')
            ->willReturn(RoutingDirective::handoff('mediamaker', ['media_type' => 'video'])->toHandlerResult());

        $imageHandler = $this->createHandlerMock('image_generation');
        $imageHandler->expects(self::once())
            ->method('handle')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(static function (array $classification): bool {
                    self::assertSame('mediamaker', $classification['topic']);
                    self::assertSame('image_generation', $classification['intent']);
                    self::assertSame('native_tool_handoff', $classification['source']);
                    self::assertTrue($classification['skip_sorting']);
                    // Recovered from the tool call, not from a classifier.
                    self::assertSame('video', $classification['media_type']);
                    // The flag is cleared, which is what bounds this to one re-route.
                    self::assertArrayNotHasKey('defer_routing_to_chat', $classification);

                    return true;
                }),
            )
            ->willReturn(['content' => 'video', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler, $imageHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
        );

        $result = $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'defer_routing_to_chat' => true],
        );

        self::assertSame(['content' => 'video', 'metadata' => []], $result);
    }

    public function testAReclassifyDirectiveGoesBackThroughTheClassifierWithDeferralOff(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::exactly(2))
            ->method('handle')
            ->willReturnOnConsecutiveCalls(
                RoutingDirective::reclassify()->toHandlerResult(),
                ['content' => 'answered after reclassification', 'metadata' => []],
            );

        $classifier = $this->createMock(MessageClassifier::class);
        $classifier->expects(self::once())
            ->method('classify')
            ->with(self::anything(), self::anything(), null, false)
            ->willReturn(['topic' => 'general', 'intent' => 'chat', 'source' => 'ai_sorting', 'language' => 'en']);

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $classifier,
        );

        $result = $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'defer_routing_to_chat' => true],
        );

        self::assertSame('answered after reclassification', $result['content']);
    }

    /**
     * Web-search results and the like are attached by the pipeline BEFORE
     * routing; taking the fresh classification wholesale would drop them.
     */
    public function testReclassificationKeepsThePipelinesTransientClassificationKeys(): void
    {
        $seen = null;
        $firstCall = true;
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->method('handle')->willReturnCallback(
            static function (Message $m, array $t, array $classification) use (&$seen, &$firstCall): array {
                if ($firstCall) {
                    $firstCall = false;

                    return RoutingDirective::reclassify()->toHandlerResult();
                }
                $seen = $classification;

                return ['content' => 'ok', 'metadata' => []];
            }
        );

        $classifier = $this->createMock(MessageClassifier::class);
        $classifier->method('classify')->willReturn(['topic' => 'general', 'intent' => 'chat', 'source' => 'ai_sorting']);

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $classifier,
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'defer_routing_to_chat' => true, 'search_results' => ['results' => ['a']]],
        );

        self::assertIsArray($seen);
        self::assertSame(['results' => ['a']], $seen['search_results']);
        self::assertSame('ai_sorting', $seen['source']);
    }

    public function testTheStreamingPathHonoursDirectivesToo(): void
    {
        // officemaker's handler IS 'chat', so the second dispatch reaches the
        // same handler with the new topic — it must answer, not loop.
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::exactly(2))
            ->method('handleStream')
            ->willReturnOnConsecutiveCalls(
                RoutingDirective::handoff('officemaker')->toHandlerResult(),
                ['content' => 'document', 'metadata' => []],
            );

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
        );

        $result = $router->routeStream(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'defer_routing_to_chat' => true],
            static function (): void {},
        );

        self::assertSame('document', $result['content']);
    }

    private function createHandlerMock(string $name): MessageHandlerInterface&MockObject
    {
        $handler = $this->createMock(MessageHandlerInterface::class);
        $handler->method('getName')->willReturn($name);

        return $handler;
    }
}
