<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\Document\Persist\DocumentRevisionService;
use App\Service\Iam\KnowledgeFolderShareCleanup;
use App\Service\RAG\VectorStorage\VectorStorageFacade;

/**
 * Deletes a knowledge folder: every file stored under its group key, then any
 * vector chunks still labeled with that key.
 *
 * Folders are not rows. The Sources grid also lists a group when only the
 * vector index still mentions it (the files themselves are already gone).
 * Deleting the files alone leaves that index behind, so the tile comes back
 * on the next reload. The group-key sweep is what actually removes the folder.
 */
final readonly class FileGroupDeletionService
{
    public function __construct(
        private FileRepository $fileRepository,
        private FileStorageService $storageService,
        private DocumentRevisionService $documentRevisionService,
        private VectorStorageFacade $vectorStorageFacade,
        private KnowledgeFolderShareCleanup $folderShareCleanup,
    ) {
    }

    /**
     * @return array{deletedFiles: int, deletedChunks: int}
     */
    public function deleteGroup(int $userId, string $groupKey): array
    {
        $deletedFiles = 0;
        foreach ($this->fileRepository->findByUserAndGroupKey($userId, $groupKey) as $file) {
            $this->deleteFile($userId, $file);
            ++$deletedFiles;
        }

        $deletedChunks = $this->vectorStorageFacade->deleteByGroupKey($userId, $groupKey);
        $this->folderShareCleanup->forgetIfEmpty($userId, $groupKey);

        return [
            'deletedFiles' => $deletedFiles,
            'deletedChunks' => $deletedChunks,
        ];
    }

    private function deleteFile(int $userId, File $file): void
    {
        $this->documentRevisionService->deleteForFile($file);

        $fileId = $file->getId();
        if (null !== $fileId) {
            $this->vectorStorageFacade->deleteByFile($userId, $fileId);
        }

        $path = $file->getFilePath();
        if ('' !== $path) {
            $this->storageService->deleteFile($path);
        }

        $this->fileRepository->delete($file);
    }
}
