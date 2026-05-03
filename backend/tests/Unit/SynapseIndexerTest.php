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

    /**
     * Build a Prompt mock with the most common defaults so individual
     * tests can override only the fields that matter for them.
     */
    private function makePrompt(
        string $topic,
        string $description = 'desc',
        ?string $keywords = null,
        bool $enabled = true,
        int $ownerId = 0,
    ): Prompt&MockObject {
        $prompt = $this->createMock(Prompt::class);
        $prompt->method('getTopic')->willReturn($topic);
        $prompt->method('getShortDescription')->willReturn($description);
        $prompt->method('getKeywords')->willReturn($keywords);
        $prompt->method('isEnabled')->willReturn($enabled);
        $prompt->method('getOwnerId')->willReturn($ownerId);

        return $prompt;
    }

    // ── indexAllTopics ──────────────────────────────────────────────────

    public function testIndexAllTopicsEmbedsAndUpserts(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('general', 'General conversation'),
            $this->makePrompt('coding', 'Programming help'),
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);
        $this->qdrantClient->expects($this->exactly(2))
            ->method('upsertSynapseTopic');

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(2, $result['indexed']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['errors']);
    }

    public function testIndexAllTopicsSkipsDisabledPrompts(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('general', 'General'),
            $this->makePrompt('disabled-topic', 'Disabled', enabled: false),
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);
        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(1, $result['indexed']);
    }

    public function testIndexAllTopicsSkipsToolPrompts(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('tools:sort', 'Sort prompt'),
            $this->makePrompt('general', 'General'),
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);
        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(1, $result['indexed']);
    }

    public function testIndexAllTopicsSkipsTopicsWithoutDescriptionAndKeywords(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('general', 'General'),
            $this->makePrompt('empty', '', null),
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);
        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(1, $result['indexed']);
    }

    public function testIndexAllTopicsReturnsZeroForNoTopics(): void
    {
        $this->promptRepository->method('findAllForUser')->willReturn([]);

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(['indexed' => 0, 'skipped' => 0, 'errors' => 0], $result);
    }

    // ── source_hash skip-when-unchanged ─────────────────────────────────

    public function testIndexSkipsWhenSourceHashUnchanged(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $prompt = $this->makePrompt('general', 'Hello world');
        $this->promptRepository->method('findAllForUser')->willReturn([$prompt]);

        $embeddingText = $this->indexer->buildEmbeddingText($prompt);
        $sourceHash = $this->indexer->computeSourceHash($embeddingText, null, 1024);

        // Existing point already carries the matching hash → skip
        $this->qdrantClient->method('getSynapseTopic')->willReturn([
            'id' => 'synapse_0_general',
            'payload' => ['source_hash' => $sourceHash],
        ]);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');
        $this->aiFacade->expects($this->never())->method('embed');

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(0, $result['indexed']);
        $this->assertSame(1, $result['skipped']);
    }

    public function testForceFlagBypassesSourceHashSkip(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $prompt = $this->makePrompt('general', 'Hello world');
        $this->promptRepository->method('findAllForUser')->willReturn([$prompt]);

        $embeddingText = $this->indexer->buildEmbeddingText($prompt);
        $sourceHash = $this->indexer->computeSourceHash($embeddingText, null, 1024);

        $this->qdrantClient->method('getSynapseTopic')->willReturn([
            'id' => 'synapse_0_general',
            'payload' => ['source_hash' => $sourceHash],
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $result = $this->indexer->indexAllTopics(null, force: true);

        $this->assertSame(1, $result['indexed']);
    }

    // ── indexTopic ──────────────────────────────────────────────────────

    public function testIndexSingleTopicLoadsFromDb(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $prompt = $this->makePrompt('coding', 'A topic about coding');
        $this->promptRepository->method('findByTopic')->willReturn($prompt);
        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())
            ->method('upsertSynapseTopic')
            ->with(
                'synapse_0_coding',
                $this->anything(),
                $this->callback(fn (array $p) => 'coding' === $p['topic'] && 0 === $p['owner_id']),
            );

        $result = $this->indexer->indexTopic('coding', 0);

        $this->assertSame('indexed', $result);
    }

    public function testIndexTopicReturnsMissingWhenPromptNotFound(): void
    {
        $this->promptRepository->method('findByTopic')->willReturn(null);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $result = $this->indexer->indexTopic('nonexistent', 0);

        $this->assertSame('missing', $result);
    }

    public function testIndexTopicSkipsEmptyDescriptionAndKeywords(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $prompt = $this->makePrompt('empty', '', null);
        $this->promptRepository->method('findByTopic')->willReturn($prompt);
        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $result = $this->indexer->indexTopic('empty', 0);

        $this->assertSame('skipped', $result);
    }

    public function testIndexTopicRemovesDisabledTopicFromIndex(): void
    {
        $prompt = $this->makePrompt('disabled', 'desc', enabled: false);
        $this->promptRepository->method('findByTopic')->willReturn($prompt);

        $this->qdrantClient->expects($this->once())
            ->method('deleteSynapseTopic')
            ->with('synapse_0_disabled');

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $result = $this->indexer->indexTopic('disabled', 0);

        $this->assertSame('skipped', $result);
    }

    // ── Removal lifecycle ───────────────────────────────────────────────

    public function testRemoveTopicDeletesSinglePoint(): void
    {
        $this->qdrantClient->expects($this->once())
            ->method('deleteSynapseTopic')
            ->with('synapse_5_billing');

        $this->qdrantClient->expects($this->never())
            ->method('deleteSynapseTopicsByOwner');

        $this->indexer->removeTopic('billing', 5);
    }

    // ── reindexForUser ──────────────────────────────────────────────────

    public function testReindexForUserDeletesAndReindexes(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->qdrantClient->expects($this->once())
            ->method('deleteSynapseTopicsByOwner')
            ->with(42);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('system', 'System topic', ownerId: 0),
            $this->makePrompt('custom', 'User custom topic', ownerId: 42),
        ]);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);
        $this->qdrantClient->expects($this->once())->method('upsertSynapseTopic');

        $result = $this->indexer->reindexForUser(42);

        $this->assertSame(1, $result['indexed']);
    }

    // ── Vector & embedding edge cases ───────────────────────────────────

    public function testVectorDimensionNormalizationPadsShortVectors(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('test', 'Test topic'),
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);

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

    public function testEmptyEmbeddingCountsAsError(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('test', 'Test topic'),
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => [],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $this->qdrantClient->expects($this->never())->method('upsertSynapseTopic');

        $result = $this->indexer->indexAllTopics();

        $this->assertSame(0, $result['indexed']);
        $this->assertSame(1, $result['errors']);
    }

    // ── Embedding model info ────────────────────────────────────────────

    public function testEmbeddingModelInfoReturnsAllNullWhenNoModel(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $info = $this->indexer->getEmbeddingModelInfo();

        $this->assertNull($info['provider']);
        $this->assertNull($info['model']);
        $this->assertNull($info['model_id']);
        $this->assertSame(1024, $info['vector_dim']);
    }

    public function testEmbeddingModelInfoReturnsProviderModelAndId(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');
        $this->modelConfigService->method('getModelName')->willReturn('@cf/baai/bge-m3');

        $info = $this->indexer->getEmbeddingModelInfo();

        $this->assertSame(42, $info['model_id']);
        $this->assertSame('cloudflare', $info['provider']);
        $this->assertSame('@cf/baai/bge-m3', $info['model']);
    }

    public function testEmbeddingModelInfoReadsVectorDimFromCatalog(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(88);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-large');
        $this->modelConfigService->method('getVectorDimForModel')->willReturn(3072);

        $info = $this->indexer->getEmbeddingModelInfo();

        $this->assertSame(3072, $info['vector_dim']);
    }

    public function testEmbeddingModelInfoFallsBackToDefaultDimWhenCatalogMissingMetadata(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('ollama');
        $this->modelConfigService->method('getModelName')->willReturn('bge-m3');
        $this->modelConfigService->method('getVectorDimForModel')->willReturn(null);

        $info = $this->indexer->getEmbeddingModelInfo();

        $this->assertSame(1024, $info['vector_dim']);
    }

    // ── buildEmbeddingText / computeSourceHash contract ─────────────────

    public function testBuildEmbeddingTextIncludesTopicDescriptionAndKeywords(): void
    {
        $prompt = $this->makePrompt('coding', 'Programming help', 'php, python, code');

        $text = $this->indexer->buildEmbeddingText($prompt);

        $this->assertStringContainsString('Topic: coding', $text);
        $this->assertStringContainsString('Description: Programming help', $text);
        $this->assertStringContainsString('Keywords: php, python, code', $text);
    }

    public function testBuildEmbeddingTextOmitsEmptyKeywordsLine(): void
    {
        $prompt = $this->makePrompt('general', 'Chat', null);

        $text = $this->indexer->buildEmbeddingText($prompt);

        $this->assertStringNotContainsString('Keywords:', $text);
    }

    public function testComputeSourceHashIsDeterministic(): void
    {
        $hashA = $this->indexer->computeSourceHash('foo', 7, 1024);
        $hashB = $this->indexer->computeSourceHash('foo', 7, 1024);

        $this->assertSame($hashA, $hashB);
    }

    public function testComputeSourceHashChangesWhenModelIdChanges(): void
    {
        $hashA = $this->indexer->computeSourceHash('foo', 7, 1024);
        $hashB = $this->indexer->computeSourceHash('foo', 8, 1024);

        $this->assertNotSame($hashA, $hashB);
    }

    public function testComputeSourceHashChangesWhenDimChanges(): void
    {
        $hashA = $this->indexer->computeSourceHash('foo', 7, 1024);
        $hashB = $this->indexer->computeSourceHash('foo', 7, 1536);

        $this->assertNotSame($hashA, $hashB);
    }

    public function testIndexUploadsExpectedPayloadFields(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('cloudflare');
        $this->modelConfigService->method('getModelName')->willReturn('bge-m3');

        $this->promptRepository->method('findAllForUser')->willReturn([
            $this->makePrompt('coding', 'Programming help', 'php,python', ownerId: 0),
        ]);

        $this->qdrantClient->method('getSynapseTopic')->willReturn(null);

        $this->aiFacade->method('embed')->willReturn([
            'embedding' => array_fill(0, 1024, 0.1),
            'usage' => ['prompt_tokens' => 10, 'total_tokens' => 10],
        ]);

        $this->qdrantClient->expects($this->once())
            ->method('upsertSynapseTopic')
            ->with(
                'synapse_0_coding',
                $this->anything(),
                $this->callback(static function (array $payload): bool {
                    return 'coding' === $payload['topic']
                        && 0 === $payload['owner_id']
                        && 'Programming help' === $payload['short_description']
                        && 'php,python' === $payload['keywords']
                        && 42 === $payload['embedding_model_id']
                        && 'cloudflare' === $payload['embedding_provider']
                        && 'bge-m3' === $payload['embedding_model']
                        && 1024 === $payload['vector_dim']
                        && isset($payload['source_hash'])
                        && isset($payload['indexed_at']);
                }),
            );

        $this->indexer->indexAllTopics();
    }
}
