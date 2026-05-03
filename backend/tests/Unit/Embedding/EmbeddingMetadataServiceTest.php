<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\ModelConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class EmbeddingMetadataServiceTest extends TestCase
{
    private ModelConfigService&MockObject $modelConfigService;
    private EmbeddingMetadataService $service;

    protected function setUp(): void
    {
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->service = new EmbeddingMetadataService($this->modelConfigService);
    }

    public function testReturnsCurrentModelDefaultsWhenNoneConfigured(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(null);

        $info = $this->service->getCurrentModel();

        self::assertNull($info['model_id']);
        self::assertSame(EmbeddingMetadataService::DEFAULT_VECTOR_DIM, $info['vector_dim']);
    }

    public function testCurrentModelReadsVectorDimFromCatalog(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(88);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-large');
        $this->modelConfigService->method('getVectorDimForModel')->willReturn(3072);

        $info = $this->service->getCurrentModel();

        self::assertSame(3072, $info['vector_dim']);
    }

    public function testCurrentModelFallsBackToDefaultWhenCatalogMissingDimension(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(13);
        $this->modelConfigService->method('getProviderForModel')->willReturn('ollama');
        $this->modelConfigService->method('getModelName')->willReturn('bge-m3');
        $this->modelConfigService->method('getVectorDimForModel')->willReturn(null);

        $info = $this->service->getCurrentModel();

        self::assertSame(EmbeddingMetadataService::DEFAULT_VECTOR_DIM, $info['vector_dim']);
    }

    public function testStaleWhenIndexedModelDiffers(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-small');

        self::assertTrue($this->service->isStale(['embedding_model_id' => 7]));
        self::assertFalse($this->service->isStale(['embedding_model_id' => 42]));
    }

    public function testStaleWhenVectorDimDiffers(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-small');

        // Same model id, different vector dim → still stale
        self::assertTrue($this->service->isStale([
            'embedding_model_id' => 42,
            'vector_dim' => 1536,
        ]));
    }

    public function testLegacyHitWithoutMetadataIsTreatedAsFresh(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-small');

        self::assertFalse($this->service->isStale(['user_id' => 1, 'text' => 'hello']));
    }

    public function testFilterStaleHitsPartitionsCorrectly(): void
    {
        $this->modelConfigService->method('getDefaultModel')->willReturn(42);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-small');

        $hits = [
            ['payload' => ['embedding_model_id' => 42], 'score' => 0.9], // fresh
            ['payload' => ['embedding_model_id' => 7], 'score' => 0.8],  // stale
            ['payload' => ['user_id' => 1], 'score' => 0.7],             // legacy → fresh
        ];

        $result = $this->service->filterStaleHits($hits);

        self::assertCount(2, $result['fresh']);
        self::assertSame(1, $result['stale_count']);
    }

    public function testInvalidateClearsCache(): void
    {
        $this->modelConfigService->expects(self::exactly(2))
            ->method('getDefaultModel')
            ->willReturnOnConsecutiveCalls(1, 2);

        // Without invalidate the second read would hit the cached value
        self::assertSame(1, $this->service->getCurrentModelId());
        $this->service->invalidate();
        self::assertSame(2, $this->service->getCurrentModelId());
    }
}
