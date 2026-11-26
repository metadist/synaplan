<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\AI\Service\AiFacade;
use App\Plug\PlugConfigService;
use App\Plug\Rerank\RerankMetrics;
use App\Plug\Rerank\RerankRegistry;
use App\Plug\Rerank\RerankStage;
use App\Repository\ConfigRepository;
use App\Repository\UserRepository;
use App\Service\ModelConfigService;
use App\Service\RAG\RagScopeResolver;
use App\Service\RAG\VectorSearchService;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VectorSearchServiceSimilarAccessTest extends TestCase
{
    public function testAForeignChunkIsNeverUsedAsTheQuery(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->once())->method('findChunk')->with(2, 'chunk-of-9')->willReturn($this->chunk(9, 'private'));
        $storage->expects($this->never())->method('findSimilar');

        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('resolve')->willReturn([new RagScope(2, null)]);

        $this->assertSame([], $this->service($storage, $scopes)->findSimilar('chunk-of-9', 2));
    }

    public function testAMissingChunkGivesNoResults(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('findChunk')->willReturn(null);
        $storage->expects($this->never())->method('findSimilar');

        $this->assertSame([], $this->service($storage, $this->createMock(RagScopeResolver::class))->findSimilar('gone', 2));
    }

    public function testAnOwnChunkIsSearched(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('findChunk')->willReturn($this->chunk(2, 'mine'));
        $storage->expects($this->once())->method('findSimilar')->with(2, 'own', 10, 0.3)->willReturn([
            new SearchResult('hit', 5, 'mine', 'text', 0.8, 1, 2),
        ]);

        $results = $this->service($storage, $this->createMock(RagScopeResolver::class))->findSimilar('own', 2);

        $this->assertCount(1, $results);
        $this->assertSame('hit', $results[0]['chunk_id']);
    }

    public function testAChunkFromASharedFolderIsSearched(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->method('findChunk')->willReturn($this->chunk(9, 'handbook'));
        $storage->expects($this->once())->method('findSimilar')->willReturn([]);

        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('resolve')->with(2, null)->willReturn([new RagScope(2, null), new RagScope(9, 'handbook')]);

        $this->assertSame([], $this->service($storage, $scopes)->findSimilar('shared', 2));
    }

    private function chunk(int $ownerId, string $groupKey): SearchResult
    {
        return new SearchResult('c', 4, $groupKey, 'text', 0.0, 1, 2, ownerId: $ownerId);
    }

    private function service(VectorStorageFacade $storage, RagScopeResolver $scopes): VectorSearchService
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $models = $this->createMock(ModelConfigService::class);
        $models->method('getDefaultModel')->willReturn(null);
        $stage = new RerankStage(
            new RerankRegistry([], new PlugConfigService($repo), $models),
            new PlugConfigService($repo),
            new RerankMetrics(new NullLogger()),
            $this->createMock(RateLimitService::class),
        );

        return new VectorSearchService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(ModelConfigService::class),
            $storage,
            $this->createMock(RateLimitService::class),
            $scopes,
            $this->createMock(UserRepository::class),
            new NullLogger(),
            $stage,
        );
    }
}
