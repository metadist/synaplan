<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Message\MessageSorter;
use App\Service\Message\SynapseRouter;
use App\Service\Message\TopicAliasResolver;
use App\Service\ModelConfigService;
use App\Service\PromptService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynapseRouterTest extends TestCase
{
    private QdrantClientInterface&MockObject $qdrantClient;
    private AiFacade&MockObject $aiFacade;
    private MessageSorter&MockObject $messageSorter;
    private PromptService&MockObject $promptService;
    private PromptRepository&MockObject $promptRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private SynapseRouter $router;

    protected function setUp(): void
    {
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->messageSorter = $this->createMock(MessageSorter::class);
        $this->promptService = $this->createMock(PromptService::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $configRepository = $this->createMock(ConfigRepository::class);

        $this->router = new SynapseRouter(
            $this->qdrantClient,
            $this->aiFacade,
            $this->messageSorter,
            $this->promptService,
            $this->promptRepository,
            $this->modelConfigService,
            $configRepository,
            new TopicAliasResolver(),
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testHighConfidenceRoutesDirectly(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.92,
                'payload' => ['topic' => 'general', 'owner_id' => 0, 'short_description' => 'General chat'],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->router->route(['BTEXT' => 'Hello, how are you?'], [], 1);

        $this->assertEquals('general', $result['topic']);
        $this->assertStringStartsWith('synapse_', $result['source']);
        $this->assertArrayHasKey('synapse_score', $result);
        $this->assertGreaterThanOrEqual(0.78, $result['synapse_score']);
    }

    public function testLowConfidenceFallsBackToAi(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.35,
                'payload' => ['topic' => 'general', 'owner_id' => 0],
            ],
        ]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'coding',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Some ambiguous message'], [], 1);

        // After Synapse v2: AI sorter may emit granular topics, but the alias
        // resolver normalises them to the canonical legacy topic before the
        // result leaves the router. The granular topic is preserved.
        $this->assertEquals('general', $result['topic']);
        $this->assertEquals('coding', $result['granular_topic']);
        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('low_confidence', $result['synapse_fallback_reason']);
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

    public function testEmptyEmbeddingFallsBackToAi(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => [],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Test'], [], 1);

        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('empty_embedding', $result['synapse_fallback_reason']);
    }

    public function testNoSearchResultsFallsBackToAi(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Test'], [], 1);

        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('no_search_results', $result['synapse_fallback_reason']);
    }

    public function testDetectsWebSearchKeywords(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.90,
                'payload' => ['topic' => 'general', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'Was ist das aktuelle Wetter in Berlin?'], [], 1);

        $this->assertTrue($result['web_search']);
    }

    public function testDetectsYearPatternAsWebSearch(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.90,
                'payload' => ['topic' => 'general', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'Bundeskanzler 2026'], [], 1);

        $this->assertTrue($result['web_search']);
    }

    /**
     * Regression test: deictic time markers like "jetzt" (German) and "now"
     * (English) are extremely common in follow-up messages such as
     *   - "jetzt ein video davon"
     *   - "now make it 4K"
     *   - "jetzt das Gleiche in blau"
     * and must not trigger a web search.
     */
    public function testFollowUpJetztDoesNotTriggerWebSearch(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.90,
                'payload' => ['topic' => 'general', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $resultDe = $this->router->route(['BTEXT' => 'jetzt das gleiche in blau'], [], 1);
        $this->assertFalse($resultDe['web_search'], '"jetzt" alone must not trigger web search');

        $resultEn = $this->router->route(['BTEXT' => 'now make it 4K'], [], 1);
        $this->assertFalse($resultEn['web_search'], '"now" alone must not trigger web search');
    }

    /**
     * Pure asset/document generation topics never benefit from web context,
     * so the keyword heuristic must be skipped — even when the message
     * contains otherwise web-search-positive keywords ("aktuell", "live",
     * year patterns). Web search must only be honored via explicit
     * `tool_internet` opt-in on the prompt.
     */
    public function testMediaTopicSkipsWebSearchHeuristicEvenWithKeyword(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_video-generation',
                'score' => 0.85,
                'payload' => ['topic' => 'video-generation', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        // Contains "aktuell" + year 2026 — would normally trigger web search,
        // but topic resolves to mediamaker so the heuristic must be skipped.
        $result = $this->router->route(
            ['BTEXT' => 'erstelle ein aktuelles video von der börse 2026'],
            [],
            1,
        );

        $this->assertEquals('mediamaker', $result['topic']);
        $this->assertFalse($result['web_search'], 'Web search heuristic must be skipped for media-generation topics');
    }

    /**
     * Even for a non-web-search topic, the explicit `tool_internet` opt-in
     * via prompt metadata still wins. This keeps the editorial control of
     * prompt authors intact.
     */
    public function testMediaTopicHonorsExplicitToolInternetOptIn(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_mediamaker',
                'score' => 0.85,
                'payload' => ['topic' => 'mediamaker', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => true],
        ]);

        $result = $this->router->route(['BTEXT' => 'erstelle ein bild'], [], 1);

        $this->assertEquals('mediamaker', $result['topic']);
        $this->assertTrue($result['web_search'], 'Explicit tool_internet=true must still enable web search');
    }

    public function testLanguageDetectionGerman(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.90,
                'payload' => ['topic' => 'general', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'Ich habe eine Frage und bitte um Hilfe'], [], 1);

        $this->assertEquals('de', $result['language']);
    }

    public function testExistingLanguagePreserved(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.90,
                'payload' => ['topic' => 'general', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'Test', 'BLANG' => 'fr'], [], 1);

        $this->assertEquals('fr', $result['language']);
    }

    public function testEmbeddingExceptionFallsBackToAi(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willThrowException(new \RuntimeException('Provider down'));

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Test'], [], 1);

        $this->assertEquals('synapse_ai_fallback', $result['source']);
        $this->assertEquals('exception', $result['synapse_fallback_reason']);
    }

    public function testVectorNormalizationPadsShortVectors(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $shortVector = array_fill(0, 512, 0.1);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => $shortVector,
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())
            ->method('searchSynapseTopics')
            ->with(
                $this->callback(fn (array $v) => 1024 === count($v)),
                $this->anything(),
                $this->anything(),
                $this->anything(),
            )
            ->willReturn([
                [
                    'id' => 'synapse_0_general',
                    'score' => 0.90,
                    'payload' => ['topic' => 'general', 'owner_id' => 0],
                ],
            ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $this->router->route(['BTEXT' => 'Test'], [], 1);
    }

    /**
     * Stale-Index detection — an indexed point whose `embedding_model_id`
     * differs from the active VECTORIZE model must NOT be routed to;
     * the router has to fall back to AI sorting with reason `stale_index`.
     */
    public function testStaleIndexFallsBackToAi(): void
    {
        // Active model = id 42 …
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-large');

        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        // … but the indexed point was embedded with model id 7 (stale).
        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.95,
                'payload' => [
                    'topic' => 'general',
                    'owner_id' => 0,
                    'embedding_model_id' => 7,
                ],
            ],
        ]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Hello, how are you?'], [], 1);

        $this->assertSame('synapse_ai_fallback', $result['source']);
        $this->assertSame('stale_index', $result['synapse_fallback_reason']);
    }

    /**
     * When indexed and current model match, the route should succeed.
     */
    public function testFreshIndexPassesStaleCheck(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_general',
                'score' => 0.92,
                'payload' => [
                    'topic' => 'general',
                    'owner_id' => 0,
                    'embedding_model_id' => 42,
                ],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->router->route(['BTEXT' => 'Hello, how are you?'], [], 1);

        $this->assertSame('general', $result['topic']);
        $this->assertStringStartsWith('synapse_', $result['source']);
    }

    /**
     * Topic alias resolution — granular `coding` topic produced by Tier-1
     * must be mapped to canonical `general` for downstream handlers, while
     * `granular_topic` keeps the original for analytics.
     */
    public function testGranularCodingTopicIsAliasedToGeneral(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_coding',
                'score' => 0.88,
                'payload' => ['topic' => 'coding', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        $result = $this->router->route(['BTEXT' => 'How do I write a PHP loop?'], [], 1);

        $this->assertSame('general', $result['topic']);
        $this->assertSame('coding', $result['granular_topic']);
    }

    /**
     * `image-generation` ─► `mediamaker` with implied media=image. The router
     * must skip its own media-detection heuristics in this case.
     */
    public function testGranularImageGenerationTopicSetsMediaImage(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_image-generation',
                'score' => 0.91,
                'payload' => ['topic' => 'image-generation', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(['BTEXT' => 'Sing me a song'], [], 1);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image-generation', $result['granular_topic']);
        $this->assertSame('image', $result['media_type']);
    }

    /**
     * AI fallback can also emit granular topics — the alias resolver must
     * be re-applied on the way out.
     */
    public function testAiFallbackGranularTopicIsAliased(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);
        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        // No search results → AI fallback
        $this->qdrantClient->method('searchSynapseTopics')->willReturn([]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'video-generation',
            'language' => 'en',
            'web_search' => false,
        ]);

        $result = $this->router->route(['BTEXT' => 'Some message'], [], 1);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('video-generation', $result['granular_topic']);
        $this->assertSame('video', $result['media_type']);
        $this->assertSame('synapse_ai_fallback', $result['source']);
    }

    /**
     * Dry-run (used by the admin "Test Routing" widget) returns the raw
     * Top-K candidates with scores, alias targets and stale-flags
     * WITHOUT mutating any state.
     */
    public function testDryRunReturnsCandidatesWithStaleAndAliasFlags(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');
        $this->modelConfigService->method('getModelName')->willReturn('bge-m3');

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.5),
            'usage' => ['prompt_tokens' => 5, 'total_tokens' => 5],
        ]);

        $this->qdrantClient->method('searchSynapseTopics')->willReturn([
            [
                'id' => 'synapse_0_coding',
                'score' => 0.81,
                'payload' => [
                    'topic' => 'coding',
                    'owner_id' => 0,
                    'embedding_model_id' => 42,
                ],
            ],
            [
                'id' => 'synapse_0_general',
                'score' => 0.55,
                'payload' => [
                    'topic' => 'general',
                    'owner_id' => 0,
                    'embedding_model_id' => 7, // stale
                ],
            ],
        ]);

        // Pure read-only — sorter must NEVER be invoked
        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->router->dryRun('How do I loop in Python?', 0, 5);

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['candidates']);

        $this->assertSame('coding', $result['candidates'][0]['topic']);
        $this->assertFalse($result['candidates'][0]['stale']);
        $this->assertSame('general', $result['candidates'][0]['alias_target']);

        $this->assertSame('general', $result['candidates'][1]['topic']);
        $this->assertTrue($result['candidates'][1]['stale']);
        $this->assertNull($result['candidates'][1]['alias_target']);

        $this->assertSame(42, $result['model']['model_id']);
        $this->assertSame('cloudflare', $result['model']['provider']);
        $this->assertSame('bge-m3', $result['model']['model']);
    }

    public function testDryRunHandlesEmptyMessage(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $result = $this->router->dryRun('', 0, 5);

        $this->assertSame('empty_message', $result['error']);
        $this->assertSame([], $result['candidates']);
    }

    public function testDryRunHandlesEmptyEmbedding(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => [],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $result = $this->router->dryRun('Hello world', 0, 5);

        $this->assertSame('empty_embedding', $result['error']);
    }
}
