<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Repository\SearchIndexRepository;
use App\Service\ModelConfigService;
use App\Service\SmartSearch\Index\SearchEmbeddingModel;
use App\Service\SmartSearch\QueryVectors;
use App\Service\UserMemoryService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class QueryVectorsTest extends TestCase
{
    private const SEARCH_MODEL = 13;

    public function testOneEmbedServesEveryStoreInTheSameModelSpace(): void
    {
        $searchModel = $this->createMock(SearchEmbeddingModel::class);
        $searchModel->expects(self::once())->method('embed')
            ->willReturn(['modelId' => self::SEARCH_MODEL, 'vectors' => [[0.6, 0.8]]]);
        $memories = $this->createMock(UserMemoryService::class);
        $memories->method('getMemoryEmbeddingModelId')->willReturn(self::SEARCH_MODEL);
        $memories->expects(self::never())->method('embedUserQuery');
        $memories->expects(self::never())->method('embedQueryForMemorySearch');

        $vectors = $this->vectors($searchModel, $memories, documentsModel: self::SEARCH_MODEL);

        self::assertSame([0.6, 0.8], $vectors->forDocuments());
        self::assertSame([0.6, 0.8], $vectors->forMemories());
        $index = $vectors->forIndex();
        self::assertNotNull($index);
        self::assertCount(SearchEmbeddingModel::DIMENSION, $index['vector']);
        self::assertSame(self::SEARCH_MODEL, $index['modelId']);
    }

    public function testAnotherModelSpaceGetsItsOwnEmbed(): void
    {
        $searchModel = $this->createMock(SearchEmbeddingModel::class);
        $searchModel->method('embed')->willReturn(['modelId' => self::SEARCH_MODEL, 'vectors' => [[1.0]]]);
        $memories = $this->createMock(UserMemoryService::class);
        $memories->method('getMemoryEmbeddingModelId')->willReturn(99);
        $memories->expects(self::once())->method('embedQueryForMemorySearch')
            ->willReturn(['embedding' => [0.1, 0.2, 0.3], 'model_id' => 99, 'model_name' => 'm', 'provider' => 'p']);

        $vectors = $this->vectors($searchModel, $memories, documentsModel: self::SEARCH_MODEL);

        self::assertSame([0.1, 0.2, 0.3], $vectors->forMemories());
        $vectors->forMemories();
        self::assertSame(0.5, $vectors->memoriesMinScore(0.5));
    }

    public function testCutoffSitsAboveTheQueryNoiseLevel(): void
    {
        $searchModel = $this->createMock(SearchEmbeddingModel::class);
        $searchModel->method('embed')->willReturn(['modelId' => self::SEARCH_MODEL, 'vectors' => [[1.0]]]);
        $repository = $this->createMock(SearchIndexRepository::class);
        $repository->expects(self::once())->method('averageSimilarity')->willReturn(0.2);

        $vectors = $this->vectors($searchModel, repository: $repository, documentsModel: self::SEARCH_MODEL);

        self::assertEqualsWithDelta(0.2 + QueryVectors::MARGIN_ABOVE_NOISE, $vectors->indexMinScore(), 1e-9);
        self::assertEqualsWithDelta(0.2 + QueryVectors::MARGIN_ABOVE_NOISE, $vectors->documentsMinScore(0.4), 1e-9);
    }

    public function testConstantVectorsOfATestModelFindNothing(): void
    {
        $searchModel = $this->createMock(SearchEmbeddingModel::class);
        $searchModel->method('embed')->willReturn(['modelId' => self::SEARCH_MODEL, 'vectors' => [[0.123]]]);
        $repository = $this->createMock(SearchIndexRepository::class);
        $repository->method('averageSimilarity')->willReturn(1.0);

        self::assertGreaterThan(1.0, $this->vectors($searchModel, repository: $repository)->indexMinScore());
    }

    public function testWithoutACatalogTheConservativeCutoffApplies(): void
    {
        $searchModel = $this->createMock(SearchEmbeddingModel::class);
        $searchModel->method('embed')->willReturn(['modelId' => self::SEARCH_MODEL, 'vectors' => [[1.0]]]);
        $repository = $this->createMock(SearchIndexRepository::class);
        $repository->method('averageSimilarity')->willReturn(null);

        self::assertSame(QueryVectors::FALLBACK_MIN_SCORE, $this->vectors($searchModel, repository: $repository)->indexMinScore());
    }

    public function testAFailingEmbedMeansKeywordSearchOnly(): void
    {
        $searchModel = $this->createMock(SearchEmbeddingModel::class);
        $searchModel->expects(self::once())->method('embed')->willThrowException(new \RuntimeException('provider down'));

        $vectors = $this->vectors($searchModel);

        self::assertTrue($vectors->indexAvailable());
        self::assertNull($vectors->forIndex());
        self::assertNull($vectors->forIndex());
        self::assertFalse($vectors->indexAvailable());
    }

    private function vectors(
        SearchEmbeddingModel $searchModel,
        ?UserMemoryService $memories = null,
        ?SearchIndexRepository $repository = null,
        ?int $documentsModel = null,
    ): QueryVectors {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelConfig->method('getDefaultModel')->willReturn($documentsModel);

        return new QueryVectors(
            7,
            'turn on groups',
            $searchModel,
            $modelConfig,
            $memories ?? $this->createMock(UserMemoryService::class),
            $repository ?? $this->createMock(SearchIndexRepository::class),
            new NullLogger(),
        );
    }
}
