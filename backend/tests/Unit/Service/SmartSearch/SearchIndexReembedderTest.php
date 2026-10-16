<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SmartSearch;

use App\Entity\RevectorizeRun;
use App\Repository\SearchIndexRepository;
use App\Service\SmartSearch\Index\SearchEmbeddingModel;
use App\Service\SmartSearch\Index\SearchIndexEmbedder;
use App\Service\SmartSearch\Index\SearchIndexReembedder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class SearchIndexReembedderTest extends TestCase
{
    public function testMovesEveryOwnersPendingRowsAndCountsTheOutcome(): void
    {
        $repository = $this->createMock(SearchIndexRepository::class);
        $repository->method('pendingCountsByUser')->with(88)->willReturn([0 => 40, 7 => 3]);
        $embedder = $this->createMock(SearchIndexEmbedder::class);
        $embedder->expects($this->exactly(2))->method('embedPendingCounted')
            ->willReturnCallback(static fn (int $userId, int $max): array => 0 === $userId
                ? ['embedded' => $max, 'failed' => 0]
                : ['embedded' => 0, 'failed' => $max]);
        $model = $this->createMock(SearchEmbeddingModel::class);
        $model->method('modelId')->willReturn(88);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('flush');

        $run = (new RevectorizeRun())->setScope(RevectorizeRun::SCOPE_SEARCH);
        (new SearchIndexReembedder($repository, $embedder, $model, $em))->execute($run);

        self::assertSame(40, $run->getChunksProcessed());
        self::assertSame(3, $run->getChunksFailed());
    }

    public function testFailsLoudlyWithoutAnEmbeddingModel(): void
    {
        $model = $this->createMock(SearchEmbeddingModel::class);
        $model->method('modelId')->willReturn(null);

        $this->expectException(\RuntimeException::class);
        (new SearchIndexReembedder(
            $this->createMock(SearchIndexRepository::class),
            $this->createMock(SearchIndexEmbedder::class),
            $model,
            $this->createMock(EntityManagerInterface::class),
        ))->execute(new RevectorizeRun());
    }
}
