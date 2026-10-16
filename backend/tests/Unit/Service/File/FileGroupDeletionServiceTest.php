<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\File\FileGroupDeletionService;
use App\Service\File\FileStorageService;
use App\Service\Iam\KnowledgeFolderShareCleanup;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use PHPUnit\Framework\TestCase;

final class FileGroupDeletionServiceTest extends TestCase
{
    public function testSweepsVectorChunksWhenNoFilesRemain(): void
    {
        $files = $this->createMock(FileRepository::class);
        $files->expects(self::once())
            ->method('findByUserAndGroupKey')
            ->with(1, 'LIST_TEST')
            ->willReturn([]);
        $files->expects(self::never())->method('delete');

        $vectors = $this->createMock(VectorStorageFacade::class);
        $vectors->expects(self::never())->method('deleteByFile');
        $vectors->expects(self::once())
            ->method('deleteByGroupKey')
            ->with(1, 'LIST_TEST')
            ->willReturn(150);

        $cleanup = $this->createMock(KnowledgeFolderShareCleanup::class);
        $cleanup->expects(self::once())->method('forgetIfEmpty')->with(1, 'LIST_TEST');

        $result = $this->service($files, $vectors, $cleanup)->deleteGroup(1, 'LIST_TEST');

        self::assertSame(['deletedFiles' => 0, 'deletedChunks' => 150], $result);
    }

    public function testDeletesStoredFilesThenSweepsTheGroup(): void
    {
        $file = $this->file(42, '01/000/note.txt');

        $files = $this->createMock(FileRepository::class);
        $files->method('findByUserAndGroupKey')->willReturn([$file]);
        $files->expects(self::once())->method('delete')->with($file);

        $storage = $this->createMock(FileStorageService::class);
        $storage->expects(self::once())->method('deleteFile')->with('01/000/note.txt');

        $revisions = $this->createMock(DocumentRevisionService::class);
        $revisions->expects(self::once())->method('deleteForFile')->with($file);

        $vectors = $this->createMock(VectorStorageFacade::class);
        $vectors->expects(self::once())->method('deleteByFile')->with(1, 42);
        $vectors->expects(self::once())->method('deleteByGroupKey')->with(1, 'LIST_TEST')->willReturn(4);

        $cleanup = $this->createMock(KnowledgeFolderShareCleanup::class);
        $cleanup->expects(self::once())->method('forgetIfEmpty')->with(1, 'LIST_TEST');

        $result = (new FileGroupDeletionService($files, $storage, $revisions, $vectors, $cleanup))
            ->deleteGroup(1, 'LIST_TEST');

        self::assertSame(1, $result['deletedFiles']);
        self::assertSame(4, $result['deletedChunks']);
    }

    private function service(
        FileRepository $files,
        VectorStorageFacade $vectors,
        KnowledgeFolderShareCleanup $cleanup,
    ): FileGroupDeletionService {
        return new FileGroupDeletionService(
            $files,
            $this->createStub(FileStorageService::class),
            $this->createStub(DocumentRevisionService::class),
            $vectors,
            $cleanup,
        );
    }

    private function file(int $id, string $path): File
    {
        $file = (new File())
            ->setUserId(1)
            ->setGroupKey('LIST_TEST')
            ->setFilePath($path);

        $idProperty = new \ReflectionProperty(File::class, 'id');
        $idProperty->setValue($file, $id);

        return $file;
    }
}
