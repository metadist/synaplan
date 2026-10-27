<?php

declare(strict_types=1);

namespace App\Service\Mcp\Notion;

use App\Entity\File;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;

/**
 * "Add to a Notion page" on one of the person's stored files.
 */
final readonly class NotionFilePush
{
    public function __construct(
        private FileRepository $files,
        private FileStorageService $storage,
        private NotionFileUpload $upload,
    ) {
    }

    /**
     * @return array{page_url: string, page_title: string, file_name: string}
     *
     * @throws NotionActionException
     */
    public function push(int $userId, int $fileId, string $page): array
    {
        $file = $this->files->findOneBy(['id' => $fileId, 'userId' => $userId]);
        if (!$file instanceof File || '' === $file->getFilePath()) {
            throw new NotionActionException('That file is not in your files. Nothing was added to Notion.');
        }

        return $this->upload->attachToPage(
            $userId,
            $page,
            $this->storage->getAbsolutePath($file->getFilePath()),
            $file->getDisplayName(),
        );
    }
}
