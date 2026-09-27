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
use App\Service\RAG\VectorStorage\DTO\StorageStats;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VectorSearchServiceSharedStatsTest extends TestCase
{
    public function testSharedFolderIsCountedWithoutTheOwnersOtherFiles(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->once())->method('getStats')->with(2)->willReturn(new StorageStats(0, 0, 0));
        $storage->expects($this->once())
            ->method('getFilesWithChunksByGroupKey')
            ->with(9, 'handbook')
            ->willReturn([
                4 => ['chunks' => 3, 'groupKey' => 'handbook'],
                5 => ['chunks' => 1, 'groupKey' => 'handbook'],
            ]);
        $storage->expects($this->never())->method('getFileChunkInfo');

        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->expects($this->once())->method('resolve')->with(2, null)->willReturn([
            new RagScope(2, null),
            new RagScope(9, 'handbook'),
        ]);

        $stats = $this->service($storage, $scopes)->getUserStats(2);

        $this->assertSame(2, $stats['total_documents']);
        $this->assertSame(4, $stats['total_chunks']);
        $this->assertSame(1, $stats['total_groups']);
    }

    public function testOwnDocumentsStayAndASharedFileIsNotCountedTwice(): void
    {
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->once())->method('getStats')->with(2)->willReturn(new StorageStats(10, 3, 1, ['mine' => 10]));
        $storage->expects($this->once())->method('getFilesWithChunksByGroupKey')->with(9, 'handbook')->willReturn([
            4 => ['chunks' => 3, 'groupKey' => 'handbook'],
        ]);
        $storage->expects($this->once())
            ->method('getFilesWithChunks')
            ->with(9)
            ->willReturn([
                4 => ['chunks' => 3, 'groupKey' => 'handbook'],
                8 => ['chunks' => 2, 'groupKey' => 'handbook'],
                7 => ['chunks' => 9, 'groupKey' => 'private'],
                99 => ['chunks' => 50, 'groupKey' => 'private'],
            ]);
        $storage->expects($this->never())->method('getFileChunkInfo');

        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('resolve')->willReturn([
            new RagScope(2, null),
            new RagScope(9, 'handbook'),
            new RagScope(9, 'handbook', [4, 8, 7]),
        ]);

        $stats = $this->service($storage, $scopes)->getUserStats(2);

        $this->assertSame(5, $stats['total_documents']);
        $this->assertSame(15, $stats['total_chunks']);
        $this->assertSame(2, $stats['total_groups']);
        $this->assertSame(['mine' => 10], $stats['chunks_by_group']);
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
