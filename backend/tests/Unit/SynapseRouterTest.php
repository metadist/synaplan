<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Repository\ConfigRepository;
use App\Repository\PromptRepository;
use App\Service\Message\MessageSorter;
use App\Service\Message\SynapseRouter;
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

        $this->assertEquals('coding', $result['topic']);
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
}
