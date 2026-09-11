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
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class VectorizationServiceHintTest extends TestCase
{
    public function testDesktopFolderIgnoresHintAndUsesSearchDefault(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelRepo = $this->createMock(EntityRepository::class);

        $modelConfig->expects($this->once())
            ->method('getDefaultModel')
            ->with('VECTORIZE', 1)
            ->willReturn(13);
        $modelRepo->method('find')->willReturn($this->embeddingModel());

        $result = $this->service($modelConfig, $modelRepo)->vectorizeAndStore(
            'some text',
            1,
            46,
            'DESKTOP:p',
            0,
            null,
            42,
        );
        $this->assertFalse($result['success']);
    }

    public function testNonDesktopExplicitBidSkipsAccountDefault(): void
    {
        $modelConfig = $this->createMock(ModelConfigService::class);
        $modelRepo = $this->createMock(EntityRepository::class);

        $modelConfig->expects($this->never())->method('getDefaultModel');
        $modelRepo->method('find')->willReturn($this->embeddingModel());

        $result = $this->service($modelConfig, $modelRepo)->vectorizeAndStore(
            'some text',
            1,
            46,
            'WIDGET:x',
            0,
            null,
            42,
        );
        $this->assertFalse($result['success']);
    }

    /**
     * @param MockObject&ModelConfigService $modelConfig
     * @param MockObject&EntityRepository   $modelRepo
     */
    private function service(MockObject $modelConfig, MockObject $modelRepo): VectorizationService
    {
        $aiFacade = $this->createMock(AiFacade::class);
        $chunker = $this->createMock(TextChunker::class);
        $storage = $this->createMock(VectorStorageFacade::class);
        $em = $this->createMock(EntityManagerInterface::class);

        $em->method('getRepository')->willReturn($modelRepo);
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

        return new VectorizationService(
            $aiFacade,
            $chunker,
            $modelConfig,
            $storage,
            $this->createStub(RateLimitService::class),
            $em,
            new NullLogger(),
        );
    }

    private function embeddingModel(): Model
    {
        $model = $this->createMock(Model::class);
        $model->method('getProviderId')->willReturn('bge-m3');
        $model->method('getService')->willReturn('ollama');

        return $model;
    }
}
