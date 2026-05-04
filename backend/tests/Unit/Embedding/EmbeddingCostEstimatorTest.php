<?php

declare(strict_types=1);

namespace App\Tests\Unit\Embedding;

use App\Service\Embedding\EmbeddingCostEstimator;
use App\Service\Embedding\EmbeddingMetadataService;
use App\Service\ModelConfigService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\DBAL\Connection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class EmbeddingCostEstimatorTest extends TestCase
{
    private QdrantClientInterface&MockObject $qdrantClient;
    private ModelConfigService&MockObject $modelConfigService;
    private Connection&MockObject $connection;
    private EmbeddingMetadataService&MockObject $embeddingMetadata;
    private EmbeddingCostEstimator $estimator;

    protected function setUp(): void
    {
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->connection = $this->createMock(Connection::class);
        $this->embeddingMetadata = $this->createMock(EmbeddingMetadataService::class);

        $this->estimator = new EmbeddingCostEstimator(
            $this->qdrantClient,
            $this->modelConfigService,
            $this->connection,
            $this->embeddingMetadata,
            $this->createMock(LoggerInterface::class),
        );
    }

    public function testClassifiesInfoForSmallTokenCounts(): void
    {
        self::assertSame('info', $this->estimator->classifySeverity(0));
        self::assertSame('info', $this->estimator->classifySeverity(500));
        self::assertSame('info', $this->estimator->classifySeverity(EmbeddingCostEstimator::THRESHOLD_WARNING - 1));
    }

    public function testClassifiesWarningAtThreshold(): void
    {
        self::assertSame('warning', $this->estimator->classifySeverity(EmbeddingCostEstimator::THRESHOLD_WARNING));
        self::assertSame('warning', $this->estimator->classifySeverity(EmbeddingCostEstimator::THRESHOLD_CRITICAL - 1));
    }

    public function testClassifiesCriticalAtThreshold(): void
    {
        self::assertSame('critical', $this->estimator->classifySeverity(EmbeddingCostEstimator::THRESHOLD_CRITICAL));
        self::assertSame('critical', $this->estimator->classifySeverity(50_000_000));
    }

    public function testEstimateChangeReturnsPerScopeBreakdown(): void
    {
        $this->embeddingMetadata->method('getCurrentModelId')->willReturn(7);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-small');

        // 100 BRAG rows × 200 chars each → ~5000 tokens for documents
        $this->connection->method('fetchOne')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'COUNT(*)')) {
                    return 100;
                }
                if (str_contains($sql, 'SUM(LENGTH')) {
                    return 20_000;
                }

                return false;
            });

        $this->qdrantClient->method('scrollMemories')->willReturn(array_fill(0, 50, ['payload' => []]));
        $this->qdrantClient->method('getSynapseCollectionInfo')->willReturn([
            'exists' => true,
            'points_count' => 30,
            'vector_dim' => 1024,
            'distance' => 'Cosine',
        ]);

        $estimate = $this->estimator->estimateChange(99);

        self::assertSame(99, $estimate['toModelId']);
        self::assertSame(7, $estimate['fromModelId']);

        // 100 documents
        self::assertSame(100, $estimate['scopes']['documents']['chunks']);
        // 20000 chars / 4 = 5000 tokens
        self::assertSame(5000, $estimate['scopes']['documents']['tokensEstimated']);

        // 50 memories × 500 tokens heuristic = 25000
        self::assertSame(50, $estimate['scopes']['memories']['chunks']);
        self::assertSame(25_000, $estimate['scopes']['memories']['tokensEstimated']);

        // 30 topics × 200 = 6000
        self::assertSame(30, $estimate['scopes']['synapse']['chunks']);
        self::assertSame(6000, $estimate['scopes']['synapse']['tokensEstimated']);

        self::assertSame(180, $estimate['totals']['chunks']);
        self::assertSame(36_000, $estimate['totals']['tokensEstimated']);
        self::assertSame('info', $estimate['severity']);
    }

    public function testCriticalSeverityForLargeRun(): void
    {
        $this->embeddingMetadata->method('getCurrentModelId')->willReturn(7);
        $this->modelConfigService->method('getProviderForModel')->willReturn('openai');
        $this->modelConfigService->method('getModelName')->willReturn('text-embedding-3-large');

        $this->connection->method('fetchOne')
            ->willReturnCallback(static function (string $sql) {
                if (str_contains($sql, 'COUNT(*)')) {
                    return 200_000;
                }
                if (str_contains($sql, 'SUM(LENGTH')) {
                    return 200_000_000; // → 50M tokens
                }

                return false;
            });

        $this->qdrantClient->method('scrollMemories')->willReturn([]);
        $this->qdrantClient->method('getSynapseCollectionInfo')->willReturn([
            'exists' => true,
            'points_count' => 0,
            'vector_dim' => 1024,
            'distance' => 'Cosine',
        ]);

        $estimate = $this->estimator->estimateChange(99);

        self::assertSame('critical', $estimate['severity']);
        self::assertSame(50_000_000, $estimate['scopes']['documents']['tokensEstimated']);
        self::assertGreaterThan(0, $estimate['scopes']['documents']['costEstimatedUsd']);
    }
}
