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

    /**
     * Regression test for issue #974.
     *
     * The most common German phrasing for asking about prices is "Was kostet
     * X?" — conjugated, not the bare infinitive. The previous keyword list
     * used the literal `kosten`, but `str_contains('kostet', 'kosten')` is
     * `false`, so neither WhatsApp nor the web chat ever triggered the Brave
     * Search pipeline for these queries. Use the shorter stem `kost` instead.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function germanCostQueryProvider(): iterable
    {
        yield 'kostet (singular)' => ['Was kostet ein Flug nach Bergen?'];
        yield 'kostet + restaurant' => ['Was kostet ein Kebap-Gericht in Münster?'];
        yield 'wie teuer' => ['Wie teuer ist ein iPhone 17?'];
        yield 'gekostet (past)' => ['Was hat das Konzert gestern gekostet?'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('germanCostQueryProvider')]
    public function testGermanCostQueriesTriggerWebSearch(string $text): void
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

        $result = $this->router->route(['BTEXT' => $text], [], 1);

        $this->assertTrue(
            $result['web_search'],
            sprintf('German cost/price query "%s" must trigger web search (#974)', $text),
        );
    }

    /**
     * Coverage for the expanded WEB_SEARCH_KEYWORDS list — the keyword
     * heuristic should fire on time-sensitive / factual / locational
     * queries spanning real estate, travel, finance, politics, sports,
     * news, weather, reviews and technology releases. Philosophy is
     * "rather search than not" for anything that benefits from current
     * data.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function expandedWebSearchTriggerProvider(): iterable
    {
        // Real estate — original failing prompt from the bug report.
        yield 'real_estate_eigentumswohnung' => ['Erzähl mir etwas über Eigentumswohnungen in München'];
        yield 'real_estate_apartment_for' => ['What is a small apartment for sale in Berlin?'];
        yield 'real_estate_mietspiegel' => ['Wie ist der Mietspiegel in Hamburg?'];

        // Travel.
        yield 'travel_flug' => ['Wann geht der nächste Flug nach Rom?'];
        yield 'travel_hotel' => ['Welche Hotels gibt es in Lissabon?'];
        yield 'travel_einreise' => ['Brauche ich ein Visa für die Einreise nach Japan?'];

        // Politics / current affairs.
        yield 'politics_kanzler' => ['Wer ist der aktuelle Bundeskanzler von Deutschland?'];
        yield 'politics_wahl' => ['Wann ist die nächste Wahl in Bayern?'];
        yield 'politics_sanctions' => ['Welche EU-Sanktionen gibt es gerade?'];

        // Sports.
        yield 'sports_bundesliga' => ['Wer führt die Bundesliga an?'];
        yield 'sports_champions_league' => ['Was waren die Ergebnisse der Champions League?'];

        // Finance.
        yield 'finance_bitcoin' => ['Wie ist der Bitcoin Kurs gerade?'];
        yield 'finance_zins' => ['Wie hoch ist der EZB Zins?'];

        // News / crisis.
        yield 'news_breaking' => ['Was sind die heutigen Schlagzeilen aus Berlin?'];
        yield 'news_war' => ['Was passiert gerade im Krieg in Osteuropa?'];

        // Local / location.
        yield 'local_oeffnungszeiten' => ['Wie sind die Öffnungszeiten vom Apple Store München?'];
        yield 'local_nearby' => ['Gibt es ein gutes Restaurant in meiner Nähe?'];

        // Reviews / comparisons.
        yield 'review_vergleich' => ['Bitte einen Vergleich der besten Laptops 2026.'];
        yield 'review_test' => ['Hast du einen Testbericht zum neuen iPhone?'];

        // Technology releases.
        yield 'tech_release' => ['Wann wird die neue PlayStation released?'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('expandedWebSearchTriggerProvider')]
    public function testExpandedKeywordHeuristicTriggersWebSearch(string $text): void
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

        $result = $this->router->route(['BTEXT' => $text], [], 1);

        $this->assertTrue(
            $result['web_search'],
            sprintf('Search-worthy query "%s" must trigger web search via keyword heuristic', $text),
        );
    }

    /**
     * Counterpart to the expanded-trigger test: timeless conceptual or
     * generic-chat queries must NOT trigger the heuristic, otherwise we
     * spend Brave Search quota on every casual message.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function nonSearchWorthyQueryProvider(): iterable
    {
        // Conceptual/educational questions phrased without any
        // WEB_SEARCH_KEYWORDS — these should answer from the model's
        // existing knowledge, not burn Brave Search quota.
        yield 'concept_explanation_de' => ['Erkläre mir bitte das Konzept der Vererbung in OOP'];
        yield 'concept_explanation_en' => ['Explain how recursion works in programming'];
        yield 'greeting' => ['Hallo, wie geht es dir?'];
        yield 'small_talk' => ['Schön dich kennenzulernen!'];
        yield 'creative_writing' => ['Schreibe ein kurzes Gedicht über den Wald'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonSearchWorthyQueryProvider')]
    public function testNonSearchWorthyQueriesDoNotTriggerWebSearch(string $text): void
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

        $result = $this->router->route(['BTEXT' => $text], [], 1);

        $this->assertFalse(
            $result['web_search'],
            sprintf('Timeless/casual query "%s" must NOT trigger web search', $text),
        );
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
     *
     * Updated for #878: the media-intent guard now requires the user's
     * text to pair a creation verb with a media noun before Tier-1 can
     * route to a granular media topic, so the message text below contains
     * an unambiguous "create an image of …" cue.
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

        $result = $this->router->route(['BTEXT' => 'Create an image of a small black cat'], [], 1);

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('image-generation', $result['granular_topic']);
        $this->assertSame('image', $result['media_type']);
    }

    /**
     * Issue #878: a high-confidence Tier-1 hit on `video-generation` must
     * be rejected when the user's text contains no explicit video-creation
     * cue. The original bug report shows messages about hobbies/business
     * ("ich beschäftige mich mit Protein shakes und möchte eine Kette
     * öffnen…") being routed to Veo because the embedding model places
     * them near the video-generation centroid.
     */
    public function testMediaIntentGuardRejectsVideoGenerationWithoutCreationCue(): void
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
                'score' => 0.86, // well above the 0.78 threshold
                'payload' => ['topic' => 'video-generation', 'owner_id' => 0],
            ],
        ]);

        $this->messageSorter->expects($this->once())->method('classify')->willReturn([
            'topic' => 'general-chat',
            'language' => 'de',
            'web_search' => false,
        ]);

        $result = $this->router->route(
            ['BTEXT' => 'ich habe ein neues hobby. ich beschäftige mich mit Protein shakes und möchte eine kette öffnen'],
            [],
            1,
        );

        $this->assertSame('synapse_ai_fallback', $result['source']);
        $this->assertSame('media_intent_guard', $result['synapse_fallback_reason']);
        // The granular alias is still attached on the AI-fallback path so
        // analytics keep parity with previous behaviour.
        $this->assertSame('general', $result['topic']);
    }

    /**
     * Counterpart to the guard test: a clear video-creation request must
     * pass through to mediamaker even though the embedding score is the
     * same as in the rejection case. Together they prove the guard
     * doesn't break the legitimate happy path.
     */
    public function testMediaIntentGuardAcceptsExplicitVideoCreationRequest(): void
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
                'score' => 0.86,
                'payload' => ['topic' => 'video-generation', 'owner_id' => 0],
            ],
        ]);

        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => [],
        ]);

        $result = $this->router->route(
            ['BTEXT' => 'erstelle ein video von einem golden retriever'],
            [],
            1,
        );

        $this->assertSame('mediamaker', $result['topic']);
        $this->assertSame('video-generation', $result['granular_topic']);
        $this->assertSame('video', $result['media_type']);
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

    /**
     * Issue #603: When Synapse embedding routing wins, the router used to
     * return `model_id`/`provider`/`model_name` while MessageClassifier and
     * the rest of the pipeline read `sorting_model_id`/`sorting_provider`/
     * `sorting_model_name` (the keys MessageSorter emits). That mismatch
     * dropped the sorting model on the floor, so no `ai_sorting_*` meta
     * was persisted on the outgoing message — the Sorting Model badge
     * stayed missing in the live SSE view AND after refresh.
     *
     * The fix: surface the embedding model under the canonical sorting_*
     * keys, since the embedding model IS what produced the routing
     * decision.
     */
    public function testEmbeddingRoutingSurfacesEmbeddingModelUnderSortingKeys(): void
    {
        // Bind a non-null embedding model so `getCurrentModelInfo()`
        // resolves to a real id/provider/name instead of the null
        // fallback used by most other tests.
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');
        $this->modelConfigService->method('getModelName')->willReturn('bge-m3');
        $this->modelConfigService->method('getVectorDimForModel')->willReturn(1024);

        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([]);
        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

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

        $this->messageSorter->expects($this->never())->method('classify');

        $result = $this->router->route(['BTEXT' => 'Hello, how are you?'], [], 1);

        $this->assertSame(42, $result['sorting_model_id']);
        $this->assertSame('cloudflare', $result['sorting_provider']);
        $this->assertSame('bge-m3', $result['sorting_model_name']);

        // Sanity check: the legacy keys (`model_id`, `provider`,
        // `model_name`) must NOT be present — they were the source of
        // the bug and downstream consumers should fail loudly rather
        // than silently fall back to null again.
        $this->assertArrayNotHasKey('model_id', $result);
        $this->assertArrayNotHasKey('provider', $result);
        $this->assertArrayNotHasKey('model_name', $result);
    }

    /**
     * Issue #603: rule-based routing short-circuits before any embedding
     * or AI call, so there is no concrete sorting model to surface. Keep
     * the keys present (so MessageClassifier sees them consistently with
     * the embedding/fallback paths) but null.
     */
    public function testRuleBasedRoutingReturnsNullSortingKeys(): void
    {
        $rulePrompt = $this->createMock(\App\Entity\Prompt::class);
        $rulePrompt->method('getTopic')->willReturn('support');
        $rulePrompt->method('getSelectionRules')->willReturn('keyword:support');

        $this->promptRepository->method('findPromptsWithSelectionRules')->willReturn([$rulePrompt]);
        $this->promptService
            ->method('matchesSelectionRules')
            ->willReturn(true);
        $this->promptService->method('getPromptWithMetadata')->willReturn([
            'metadata' => ['tool_internet' => false],
        ]);

        // Embedding must not be called for a rule-based match.
        $this->aiFacade->expects($this->never())->method('embed');

        $result = $this->router->route(['BTEXT' => 'I need support please'], [], 1);

        $this->assertSame('synapse_rule', $result['source']);
        $this->assertArrayHasKey('sorting_model_id', $result);
        $this->assertArrayHasKey('sorting_provider', $result);
        $this->assertArrayHasKey('sorting_model_name', $result);
        $this->assertNull($result['sorting_model_id']);
        $this->assertNull($result['sorting_provider']);
        $this->assertNull($result['sorting_model_name']);
    }
}
