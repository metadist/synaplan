<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message;

use App\Entity\Message;
use App\Entity\Prompt;
use App\Service\Agent\AgentConfig;
use App\Service\Message\Capability\SystemCapabilityRegistry;
use App\Service\Message\Capability\TopicIntentRule;
use App\Service\Message\Exception\UnmappedIntentException;
use App\Service\Message\Handler\MessageHandlerInterface;
use App\Service\Message\InferenceRouter;
use App\Service\Message\MessageClassifier;
use App\Service\Message\Processing\TurnClassificationResolver;
use App\Service\Message\Routing\RoutingDirective;
use App\Service\ModelConfigService;
use App\Service\Prompt\PromptService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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
            $this->resolver(),
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
            $this->resolver(),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'image_generation', 'topic' => 'mediamaker'],
        );
    }

    /**
     * Issue #1910 / #1908: MessageClassifier emits intent file_analysis / topic
     * analyzefile, and FileAnalysisHandler is registered under that name, but
     * SystemCapabilityRegistry has no such capability. The local map must send
     * attachment turns there — otherwise pending/failed STT copy never runs
     * and every attachment fell through `?? 'chat'` to ChatHandler.
     */
    public function testFileAnalysisIntentRoutesToTheFileAnalysisHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $analysisHandler = $this->createHandlerMock('file_analysis');
        $analysisHandler->expects(self::once())
            ->method('handle')
            ->willReturn(['content' => 'extracted', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler, $analysisHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $result = $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'file_analysis', 'topic' => 'analyzefile'],
        );

        self::assertSame(['content' => 'extracted', 'metadata' => []], $result);
    }

    public function testFileAnalysisHandlerFailureDoesNotFallBackToChat(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $analysisHandler = $this->createHandlerMock('file_analysis');
        $analysisHandler->expects(self::once())
            ->method('handle')
            ->willThrowException(new \RuntimeException('File not found: gone.png'));

        $router = new InferenceRouter(
            [$chatHandler, $analysisHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File not found: gone.png');

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'file_analysis', 'topic' => 'analyzefile'],
        );
    }

    public function testFileAnalysisStreamFailureDoesNotFallBackToChat(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handleStream');

        $analysisHandler = $this->createHandlerMock('file_analysis');
        $analysisHandler->expects(self::once())
            ->method('handleStream')
            ->willThrowException(new \RuntimeException('provider exploded: SAFETY'));

        $router = new InferenceRouter(
            [$chatHandler, $analysisHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('provider exploded: SAFETY');

        $router->routeStream(
            $this->createMock(Message::class),
            [],
            ['intent' => 'file_analysis', 'topic' => 'analyzefile'],
            static function (): void {},
        );
    }

    public function testUnknownIntentDoesNotFallBackToTheChatHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $this->expectException(UnmappedIntentException::class);
        $this->expectExceptionMessage('No message handler for intent "some_unregistered_intent".');

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'some_unregistered_intent'],
        );
    }

    /**
     * document_export is a real capability, but InferenceRouter has no handler
     * for it. The old `?? 'chat'` default would answer with a chat turn that
     * only claims a PDF was produced.
     */
    public function testPlannerOnlyIntentDoesNotFallBackToTheChatHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $this->expectException(UnmappedIntentException::class);
        $this->expectExceptionMessage('No message handler for intent "document_export".');

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'document_export'],
        );
    }

    public function testSummarizeIntentRoutesToTheChatHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::once())
            ->method('handle')
            ->willReturn(['content' => 'summary', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $result = $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'summarize'],
        );

        self::assertSame(['content' => 'summary', 'metadata' => []], $result);
    }

    public function testVideoGenerationIntentRoutesToTheImageGenerationHandler(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $imageHandler = $this->createHandlerMock('image_generation');
        $imageHandler->expects(self::once())
            ->method('handle')
            ->willReturn(['content' => 'video', 'metadata' => []]);

        $router = new InferenceRouter(
            [$imageHandler, $chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'video_generation'],
        );
    }

    public function testAMissingRegisteredHandlerDoesNotFallBackToChat(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::never())->method('handle');

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(),
        );

        $this->expectException(UnmappedIntentException::class);
        $this->expectExceptionMessage('No message handler for intent "image_generation".');

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'image_generation'],
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
            $this->resolver(),
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
            $this->resolver(),
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
            $this->resolver(),
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
            $this->resolver(),
        );

        $result = $router->routeStream(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'defer_routing_to_chat' => true],
            static function (): void {},
        );

        self::assertSame('document', $result['content']);
    }

    public function testAStreamedHandOffAnswersWithThePromptOfTheTopicItLandsOn(): void
    {
        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->method('handleStream')
            ->willReturn(RoutingDirective::handoff('mediamaker', ['media_type' => 'image'])->toHandlerResult());

        $received = null;
        $imageHandler = $this->createHandlerMock('image_generation');
        $imageHandler->method('handleStream')->willReturnCallback(
            static function (Message $m, array $t, array $classification, callable $s, ?callable $p, array $options) use (&$received): array {
                $received = [$classification, $options];

                return ['content' => 'image', 'metadata' => []];
            }
        );

        $router = new InferenceRouter(
            [$chatHandler, $imageHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(['mediamaker' => ['tool_internet' => false]]),
        );

        // The general prompt pins a chat model; the image must not be generated with it.
        $router->routeStream(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'language' => 'en', 'defer_routing_to_chat' => true, 'prompt_metadata' => ['aiModel' => 12]],
            static function (): void {},
            null,
            ['resolved_prompt_data' => self::promptData('general', ['aiModel' => 12])],
        );

        self::assertIsArray($received);
        [$classification, $options] = $received;
        self::assertSame(['tool_internet' => false], $classification['prompt_metadata']);
        self::assertSame('mediamaker', $options['resolved_prompt_data']['prompt']->getTopic());
    }

    public function testThePlannersFileFallbackAnswersWithTheFileAnalysisPrompt(): void
    {
        $received = null;
        $fileHandler = $this->createHandlerMock('file_analysis');
        $fileHandler->method('handle')->willReturnCallback(
            static function (Message $m, array $t, array $classification) use (&$received): array {
                $received = $classification;

                return ['content' => 'analysis', 'metadata' => []];
            }
        );

        $router = new InferenceRouter(
            [$fileHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            $this->resolver(['analyzefile' => ['aiModel' => 0]]),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'file_analysis', 'topic' => 'analyzefile', 'language' => 'en', 'prompt_metadata' => ['aiModel' => 12]],
            null,
            ['resolved_prompt_data' => self::promptData('general', ['aiModel' => 12])],
        );

        self::assertSame(['aiModel' => 0], $received['prompt_metadata'] ?? null);
    }

    public function testATurnOnTheTopicItsPromptWasResolvedForKeepsThatPrompt(): void
    {
        $prompts = $this->createMock(PromptService::class);
        $prompts->expects(self::never())->method('getPromptWithMetadata');

        $chatHandler = $this->createHandlerMock('chat');
        $chatHandler->expects(self::once())
            ->method('handle')
            ->with(self::anything(), self::anything(), self::callback(static fn (array $classification): bool => ['aiModel' => 12] === $classification['prompt_metadata']))
            ->willReturn(['content' => 'ok', 'metadata' => []]);

        $router = new InferenceRouter(
            [$chatHandler],
            $this->createMock(LoggerInterface::class),
            new SystemCapabilityRegistry(),
            $this->createMock(MessageClassifier::class),
            new TurnClassificationResolver($this->createStub(MessageClassifier::class), $this->createStub(ModelConfigService::class), $this->createStub(AgentConfig::class), $prompts, new TopicIntentRule(new SystemCapabilityRegistry()), new NullLogger()),
        );

        $router->route(
            $this->createMock(Message::class),
            [],
            ['intent' => 'chat', 'topic' => 'general', 'prompt_metadata' => ['aiModel' => 12]],
            null,
            ['resolved_prompt_data' => self::promptData('general', ['aiModel' => 12])],
        );
    }

    /**
     * A resolver whose prompt lookup knows the given topics' metadata.
     *
     * @param array<string, array<string, mixed>> $metadataByTopic
     */
    private function resolver(array $metadataByTopic = []): TurnClassificationResolver
    {
        $prompts = $this->createStub(PromptService::class);
        $prompts->method('getPromptWithMetadata')->willReturnCallback(
            static fn (string $topic): ?array => isset($metadataByTopic[$topic]) ? self::promptData($topic, $metadataByTopic[$topic]) : null
        );

        return new TurnClassificationResolver(
            $this->createStub(MessageClassifier::class),
            $this->createStub(ModelConfigService::class),
            $this->createStub(AgentConfig::class),
            $prompts,
            new TopicIntentRule(new SystemCapabilityRegistry()),
            new NullLogger(),
        );
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array{prompt: Prompt, metadata: array<string, mixed>}
     */
    private static function promptData(string $topic, array $metadata): array
    {
        return ['prompt' => (new Prompt())->setTopic($topic), 'metadata' => $metadata];
    }

    private function createHandlerMock(string $name): MessageHandlerInterface&MockObject
    {
        $handler = $this->createMock(MessageHandlerInterface::class);
        $handler->method('getName')->willReturn($name);

        return $handler;
    }
}
