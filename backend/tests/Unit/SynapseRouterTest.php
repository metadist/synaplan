<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Repository\PromptRepository;
use App\Service\Message\MessageSorter;
use App\Service\Message\RouterClient;
use App\Service\Message\SynapseRouter;
use App\Service\Message\TopicAliasResolver;
use App\Service\PromptService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynapseRouterTest extends TestCase
{
    private MessageSorter&MockObject $messageSorter;
    private RouterClient&MockObject $routerClient;
    private PromptService&MockObject $promptService;
    private PromptRepository&MockObject $promptRepository;
    private SynapseRouter $router;

    protected function setUp(): void
    {
        $this->messageSorter = $this->createMock(MessageSorter::class);
        $this->routerClient = $this->createMock(RouterClient::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);

        $this->routerClient->method('getConfidenceThreshold')->willReturn(0.80);

        $this->router = new SynapseRouter(
            $this->messageSorter,
            $this->routerClient,
            $this->promptService,
            $this->promptRepository,
            new TopicAliasResolver(),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testExternalRouterHighConfidenceRoutesDirectly(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'text_chat',
            'confidence' => 0.92,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 3.1,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->router->route(['BTEXT' => 'Hello, how are you?'], [], 1);

        $this->assertEquals('general', $result['topic']);
        $this->assertEquals('synapse_external_router', $result['source']);
        $this->assertEquals('synaplan-router', $result['sorting_provider']);
        $this->assertGreaterThanOrEqual(0.80, $result['synapse_score']);
    }

    public function testExternalRouterLowConfidenceFallsBackToAi(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'text_chat',
            'confidence' => 0.55,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.8,
        ]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => true,
        ]);

        $result = $this->router->route(['BTEXT' => 'Some ambiguous message'], [], 1);

        $this->assertEquals('general', $result['topic']);
        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('router_miss', $result['synapse_fallback_reason']);
    }

    public function testRouterUnavailableFallsBackToAi(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);
        $this->routerClient->method('classify')->willReturn(null);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Test message'], [], 1);

        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('router_miss', $result['synapse_fallback_reason']);
    }

    public function testEmptyMessageFallsBackToAi(): void
    {
        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => ''], [], 1);

        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('empty_message', $result['synapse_fallback_reason']);
    }

    public function testRuleBasedRoutingTakesPriority(): void
    {
        $rulePrompt = $this->createMock(\App\Entity\Prompt::class);
        $rulePrompt->method('getTopic')->willReturn('support');
        $rulePrompt->method('getSelectionRules')->willReturn('keyword:support');

        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([$rulePrompt]);
        $this->promptService->method('matchesSelectionRules')->willReturn(true);
        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $this->routerClient->expects($this->never())->method('classify');
        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->router->route(['BTEXT' => 'I need support please'], [], 1);

        $this->assertSame('synapse_rule', $result['source']);
        $this->assertSame('support', $result['topic']);
        $this->assertNull($result['sorting_model_id']);
        $this->assertNull($result['sorting_provider']);
        $this->assertNull($result['sorting_model_name']);
    }

    public function testImageGenerationRouting(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'image_generation',
            'confidence' => 0.91,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.5,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Erstelle ein Bild von einem Sonnenuntergang'], [], 1);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image_generation', $result['granular_topic']);
        $this->assertSame('image', $result['media_type']);
        $this->assertFalse($result['web_search']);
    }

    public function testCompoundRoutingWithSteps(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'compound_research_image',
            'confidence' => 0.88,
            'is_compound' => true,
            'steps' => [
                ['id' => 'step_1', 'capability' => 'CHAT', 'web_search' => true],
                ['id' => 'step_2', 'capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
            ],
            'model_version' => 'v20260528',
            'latency_ms' => 3.2,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Recherchiere Katzen und erstelle ein Bild davon'], [], 1);

        $this->assertTrue($result['is_compound']);
        $this->assertCount(2, $result['router_steps']);
        $this->assertEquals('setfit', $result['classification_source']);
        // Step 1's web_search flag should drive the top-level decision.
        $this->assertTrue($result['web_search']);
        // Compound use-case resolves to step 1's canonical topic ("general").
        $this->assertEquals('general', $result['topic']);
        $this->assertEquals('compound_research_image', $result['granular_topic']);
    }

    public function testCompoundWithoutSearchInFirstStep(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'compound_image_email',
            'confidence' => 0.85,
            'is_compound' => true,
            'steps' => [
                ['id' => 'step_1', 'capability' => 'IMAGE_GENERATION', 'media_type' => 'image'],
                ['id' => 'step_2', 'capability' => 'EMAIL_SEND'],
            ],
            'model_version' => 'v20260528',
            'latency_ms' => 2.8,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Erstelle ein Bild und sende es per Mail'], [], 1);

        $this->assertTrue($result['is_compound']);
        $this->assertFalse($result['web_search']);
        $this->assertEquals('mediamaker', $result['topic']);
    }

    /**
     * When the router classifies as `text_chat`, web search is suppressed
     * because the router explicitly distinguishes `text_chat` (no search)
     * from `web_search` (search needed).
     */
    public function testTextChatSuppressesWebSearch(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'text_chat',
            'confidence' => 0.90,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.0,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Hey wie gehts dir?'], [], 1);

        $this->assertFalse($result['web_search']);
    }

    /**
     * When the router classifies as `web_search`, web search is enabled.
     */
    public function testWebSearchUseCaseEnablesWebSearch(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'web_search',
            'confidence' => 0.88,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.5,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Was ist das aktuelle Wetter in Berlin?'], [], 1);

        $this->assertTrue($result['web_search']);
    }

    public function testExplicitToolInternetFalseSuppressesSearch(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'text_chat',
            'confidence' => 0.90,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.0,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'Was ist das aktuelle Wetter in Berlin?'], [], 1);

        $this->assertFalse($result['web_search']);
    }

    public function testMediaTopicSkipsWebSearch(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'video_generation',
            'confidence' => 0.85,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.5,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Erstelle ein Video'], [], 1);

        $this->assertFalse($result['web_search']);
    }

    public function testLanguageDetectionGerman(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'text_chat',
            'confidence' => 0.90,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.0,
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'Ich habe eine Frage und bitte um Hilfe'], [], 1);

        $this->assertEquals('de', $result['language']);
    }

    public function testAiFallbackGranularTopicIsAliased(): void
    {
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);
        $this->routerClient->method('classify')->willReturn(null);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'video-generation',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Make a video'], [], 1);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('video-generation', $result['granular_topic']);
        $this->assertSame('video', $result['media_type']);
        $this->assertSame('synapse_ai_fallback', $result['source']);
    }

    public function testDryRunWithRouterAvailable(): void
    {
        $this->routerClient->method('classify')->willReturn([
            'use_case' => 'coding',
            'confidence' => 0.85,
            'is_compound' => false,
            'steps' => [],
            'model_version' => 'v20260528',
            'latency_ms' => 2.1,
        ]);

        $result = $this->router->dryRun('How do I loop in Python?', 1);

        $this->assertNull($result['error']);
        $this->assertTrue($result['router_available']);
        $this->assertNotNull($result['classification']);
        $this->assertSame('coding', $result['classification']['use_case']);
        $this->assertSame('general', $result['classification']['canonical_topic']);
        $this->assertSame('general', $result['classification']['alias_target']);
    }

    public function testDryRunWithRouterUnavailable(): void
    {
        $this->routerClient->method('classify')->willReturn(null);

        $result = $this->router->dryRun('Hello world', 1);

        $this->assertNull($result['error']);
        $this->assertFalse($result['router_available']);
        $this->assertNull($result['classification']);
        $this->assertSame('router_unavailable_or_disabled', $result['fallback_reason']);
    }

    public function testDryRunHandlesEmptyMessage(): void
    {
        $result = $this->router->dryRun('', 1);

        $this->assertSame('empty_message', $result['error']);
    }
}
