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

final class VectorizationServiceHintTest extends TestCase
{
    public function testExplicitEmbeddingBidSkipsAccountDefault(): void
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
        $em->method('getRepository')->willReturn($modelRepo);

        $modelConfig->expects($this->never())->method('getDefaultModel');

        $chunker->method('chunkify')->willReturn([
            ['content' => 'chunk', 'start_line' => 1, 'end_line' => 1],
        ]);
        $aiFacade->method('embedBatch')->willReturn([
            'embeddings' => [[]],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);
        $aiFacade->method('embed')->willReturn([
            'embedding' => [],
            'usage' => ['prompt_tokens' => 0, 'total_tokens' => 0],
        ]);
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

        $result = $service->vectorizeAndStore('some text', 1, 46, 'DESKTOP:p', 0, null, 42);
        $this->assertFalse($result['success']);
    }
}
