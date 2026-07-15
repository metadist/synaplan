<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\AI\Service\AiFacade;
use App\Entity\Model;
use App\Service\File\TextChunker;
use App\Service\File\VectorizationService;
use App\Service\ModelConfigService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\RateLimitService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * #1344: vectorizeAndStore must fail when every chunk embedding is empty,
 * otherwise callers mark BSTATUS=vectorized with zero BRAG rows.
 */
final class VectorizationServiceZeroChunksTest extends TestCase
{
    public function testReturnsFailureWhenAllEmbeddingsEmpty(): void
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $chunker = $this->createMock(TextChunker::class);
        $modelConfig = $this->createMock(ModelConfigService::class);
        $storage = $this->createMock(VectorStorageFacade::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $model = $this->createMock(Model::class);
        $model->method('getProviderId')->willReturn('bge-m3');
        $model->method('getService')->willReturn('ollama');

        $modelRepo = $this->createMock(EntityRepository::class);
        $modelRepo->method('find')->willReturn($model);

        $userRepo = $this->createMock(EntityRepository::class);
        $userRepo->method('find')->willReturn(null);

        $em->method('getRepository')->willReturnCallback(
            static function (string $class) use ($modelRepo, $userRepo) {
                return str_contains($class, 'User') ? $userRepo : $modelRepo;
            }
        );

        $modelConfig->method('getDefaultModel')->willReturn(99);

        $chunker->method('chunkify')->willReturn([
            ['content' => 'chunk one', 'start_line' => 1, 'end_line' => 1],
            ['content' => 'chunk two', 'start_line' => 2, 'end_line' => 2],
        ]);

        // Batch returns empty vectors → resilient path skips every chunk.
        $aiFacade->method('embedBatch')->willReturn([
            'embeddings' => [[], []],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);
        $aiFacade->method('embed')->willReturn([
            'embedding' => [],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);

        $storage->expects($this->never())->method('storeChunkBatch');
        $storage->method('getProviderName')->willReturn('mariadb');

        $service = new VectorizationService(
            $aiFacade,
            $chunker,
            $modelConfig,
            $storage,
            $this->createStub(RateLimitService::class),
            $em,
            new NullLogger(),
        );

        $result = $service->vectorizeAndStore('some text', 1, 46);

        $this->assertFalse($result['success']);
        $this->assertSame(0, $result['chunks_created']);
        $this->assertNotNull($result['error']);
        $this->assertStringContainsString('No chunks could be embedded', (string) $result['error']);
    }
}
