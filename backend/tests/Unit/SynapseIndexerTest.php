<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Service\AiFacade;
use App\Entity\Prompt;
use App\Repository\PromptRepository;
use App\Service\Message\SynapseIndexer;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class SynapseIndexerTest extends TestCase
{
    private QdrantClientInterface&MockObject $qdrantClient;
    private AiFacade&MockObject $aiFacade;
    private PromptRepository&MockObject $promptRepository;
    private ModelConfigService&MockObject $modelConfigService;
    private SynapseIndexer $indexer;

    protected function setUp(): void
    {
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->promptRepository = $this->createMock(PromptRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);

        $this->indexer = new SynapseIndexer(
            $this->qdrantClient,
            $this->aiFacade,
            $this->promptRepository,
            $this->modelConfigService,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testIndexAllTopicsEmbedsAndUpserts(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'general', 'description' => 'General conversation', 'ownerId' => 0],
            ['topic' => 'coding', 'description' => 'Programming help', 'ownerId' => 0],
        ]);

        $embedding = array_fill(0, 1024, 0.1);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => $embedding,
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->exactly(2))
            ->method('upsertSynapseTopic');

        $count = $this->indexer->indexAllTopics();

        $this->assertEquals(2, $count);
    }

    public function testIndexAllTopicsSkipsEmptyDescriptions(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'general', 'description' => 'General conversation', 'ownerId' => 0],
            ['topic' => 'empty', 'description' => '', 'ownerId' => 0],
        ]);

        $embedding = array_fill(0, 1024, 0.1);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => $embedding,
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $count = $this->indexer->indexAllTopics();

        $this->assertEquals(1, $count);
    }

    public function testIndexAllTopicsReturnsZeroForNoTopics(): void
    {
        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([]);

        $count = $this->indexer->indexAllTopics();

        $this->assertEquals(0, $count);
    }

    public function testIndexSingleTopicLoadsFromDb(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getShortDescription')->willReturn('A topic about coding');
        $this->promptRepository->method('findByTopic')->willReturn($prompt);

        $embedding = array_fill(0, 1024, 0.1);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => $embedding,
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())
            ->method('upsertSynapseTopic')
            ->with(
                'synapse_0_coding',
                $this->anything(),
                $this->callback(fn (array $p) => 'coding' === $p['topic'] && 0 === $p['owner_id']),
            );

        $this->indexer->indexTopic('coding', 0);
    }

    public function testIndexSingleTopicSkipsMissingPrompt(): void
    {
        $this->promptRepository->method('findByTopic')->willReturn(null);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $this->indexer->indexTopic('nonexistent', 0);
    }

    public function testIndexSingleTopicSkipsEmptyDescription(): void
    {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getShortDescription')->willReturn('');
        $this->promptRepository->method('findByTopic')->willReturn($prompt);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $this->indexer->indexTopic('empty', 0);
    }

    public function testReindexForUserDeletesAndReindexes(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->qdrantClient->expects($this->once())
            ->method('deleteSynapseTopicsByOwner')
            ->with(42);

        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'system', 'description' => 'System topic', 'ownerId' => 0],
            ['topic' => 'custom', 'description' => 'User custom topic', 'ownerId' => 42],
        ]);

        $embedding = array_fill(0, 1024, 0.1);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => $embedding,
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $count = $this->indexer->reindexForUser(42);

        $this->assertEquals(1, $count);
    }

    public function testVectorDimensionNormalization(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'test', 'description' => 'Test topic', 'ownerId' => 0],
        ]);

        $shortVector = array_fill(0, 512, 0.5);
        $this->aiFacade->method('embed')->willReturn([
            'embedding' => $shortVector,
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())
            ->method('upsertSynapseTopic')
            ->with(
                $this->anything(),
                $this->callback(fn (array $v) => 1024 === count($v)),
                $this->anything(),
            );

        $this->indexer->indexAllTopics();
    }

    public function testEmptyEmbeddingSkipsTopic(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('getTopicsWithDescriptions')->willReturn([
            ['topic' => 'test', 'description' => 'Test topic', 'ownerId' => 0],
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => [],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $count = $this->indexer->indexAllTopics();

        $this->assertEquals(0, $count);
    }

    public function testEmbeddingModelInfoReturnsNullWhenNoModel(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $info = $this->indexer->getEmbeddingModelInfo();

        $this->assertNull($info['provider']);
        $this->assertNull($info['model']);
    }
}
