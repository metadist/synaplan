<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam\Erasure;

use App\Repository\RagDocumentRepository;
use App\Service\Iam\Erasure\KnowledgeEraser;
use App\Service\Iam\Erasure\UserErasure;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use PHPUnit\Framework\TestCase;

/**
 * Knowledge-base rows go inside the transaction; the vector store, which a
 * rollback cannot restore, is purged only after the commit.
 */
final class KnowledgeEraserTest extends TestCase
{
    public function testRemovesTheRowsNowAndPurgesTheVectorStoreAfterTheCommit(): void
    {
        $rows = $this->createMock(RagDocumentRepository::class);
        $rows->expects(self::once())->method('deleteByUser')->with(42);
        $purged = [];
        $vectors = $this->createStub(VectorStorageFacade::class);
        $vectors->method('deleteAllForUser')->willReturnCallback(static function (int $userId) use (&$purged): int {
            $purged[] = $userId;

            return 3;
        });

        $erasure = new UserErasure(42, 'ada@example.test');
        (new KnowledgeEraser($rows, $vectors))->erase($erasure);

        self::assertSame([], $purged, 'The vector store may only be purged after the commit.');
        foreach ($erasure->pendingCleanups() as $cleanup) {
            $cleanup();
        }
        self::assertSame([42], $purged);
    }
}
