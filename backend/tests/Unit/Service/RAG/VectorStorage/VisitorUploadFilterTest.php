<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\RAG\VectorStorage;

use App\Repository\VisitorUploadRepository;
use App\Service\RAG\VectorStorage\DTO\SearchResult;
use App\Service\RAG\VectorStorage\VisitorUploadFilter;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class VisitorUploadFilterTest extends TestCase
{
    public function testChunksOfAVisitorUploadAreDroppedAndTheRestKeepTheirOrder(): void
    {
        $uploads = $this->createMock(VisitorUploadRepository::class);
        $uploads->expects($this->once())->method('idsAmong')->with([11, 359, 12])->willReturn([359]);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with(
            $this->stringContains('visitor uploads'),
            ['file_ids' => [359]],
        );

        $kept = (new VisitorUploadFilter($uploads, $logger))->withoutVisitorUploads([
            $this->chunk('a', 11),
            $this->chunk('b', 359),
            $this->chunk('c', 12),
            $this->chunk('d', 359),
        ]);

        self::assertSame(['a', 'c'], array_map(static fn (SearchResult $r): int|string => $r->chunkId, $kept));
    }

    public function testCuratedKnowledgeIsReturnedUnchanged(): void
    {
        $uploads = $this->createStub(VisitorUploadRepository::class);
        $uploads->method('idsAmong')->willReturn([]);
        $results = [$this->chunk('a', 11), $this->chunk('b', 12)];

        self::assertSame($results, (new VisitorUploadFilter($uploads, new NullLogger()))->withoutVisitorUploads($results));
    }

    private function chunk(string $id, int $fileId): SearchResult
    {
        return new SearchResult($id, $fileId, 'WIDGET:wdg_test', 'text', 0.9, 0, 1);
    }
}
