<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Service\File\FileListService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A folder may be named "0". PHP treats that string as false, which used to
 * drop the group filter and list every file of the owner.
 */
final class FileListServiceGroupKeyTest extends TestCase
{
    public function testFolderNamedZeroStillScopesTheListing(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::once())
            ->method('findByUserPaginated')
            ->with(9, '0', 0, 20, [4], [])
            ->willReturn(['files' => [], 'total' => 0]);

        $vectors = $this->createMock(VectorStorageFacade::class);
        $vectors->expects(self::once())
            ->method('getFileIdsByGroupKey')
            ->with(9, '0')
            ->willReturn([4]);
        $vectors->method('getFilesWithChunks')->willReturn([]);

        $result = $this->service($files, $vectors)->buildListing(9, '0', 0, 20, []);

        self::assertSame(['files' => [], 'total' => 0], $result);
    }

    public function testBlankGroupKeyDoesNotScopeTheListing(): void
    {
        $seen = [];
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::exactly(2))
            ->method('findByUserPaginated')
            ->willReturnCallback(function (int $userId, ?string $groupKey) use (&$seen): array {
                self::assertSame(9, $userId);
                $seen[] = $groupKey;

                return ['files' => [], 'total' => 0];
            });

        $vectors = $this->createMock(VectorStorageFacade::class);
        $vectors->expects(self::never())->method('getFileIdsByGroupKey');
        $vectors->method('getFilesWithChunks')->willReturn([]);

        $service = $this->service($files, $vectors);
        $service->buildListing(9, null, 0, 20, []);
        $service->buildListing(9, '', 0, 20, []);

        self::assertSame([null, ''], $seen);
    }

    private function service(FileRepository $files, VectorStorageFacade $vectors): FileListService
    {
        return new FileListService(
            $files,
            $this->createStub(MessageRepository::class),
            $vectors,
            $this->createStub(LoggerInterface::class),
        );
    }
}
