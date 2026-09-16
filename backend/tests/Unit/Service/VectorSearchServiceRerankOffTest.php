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
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VectorSearchServiceRerankOffTest extends TestCase
{
    public function testStorageReceivesLimitKAndHitsHaveNoRerankKeys(): void
    {
        $seenLimit = null;
        $storage = $this->createMock(VectorStorageFacade::class);
        $storage->expects($this->once())->method('search')->willReturnCallback(
            static function (SearchQuery $query) use (&$seenLimit): array {
                $seenLimit = $query->limit;

                return [
                    new SearchResult(1, 10, 'g', 'hello', 0.9, 0, 1, 'sample.md', 'text/markdown', 1, null, false),
                ];
            }
        );

        $scopes = $this->createMock(RagScopeResolver::class);
        $scopes->method('resolve')->willReturn([new RagScope(1, 'g')]);

        $users = $this->createMock(UserRepository::class);
        $users->method('findBy')->willReturn([]);

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

        $service = new VectorSearchService(
            $this->createMock(EntityManagerInterface::class),
            $this->createMock(AiFacade::class),
            $this->createMock(ModelConfigService::class),
            $storage,
            $this->createMock(RateLimitService::class),
            $scopes,
            $users,
            new NullLogger(),
            $stage,
        );

        $hits = $service->semanticSearchByVector(1, array_fill(0, 1024, 0.1), 'g', 7, 0.3, 'a question');

        $this->assertSame(7, $seenLimit);
        $this->assertArrayNotHasKey('rerank_score', $hits[0]);
        $this->assertArrayNotHasKey('rerank', $hits[0]);
        $this->assertSame('hello', $hits[0]['chunk_text']);
    }
}
