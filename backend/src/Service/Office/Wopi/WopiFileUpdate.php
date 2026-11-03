<?php

declare(strict_types=1);

namespace App\Service\Office\Wopi;

use App\Entity\File;
use App\Entity\User;
use App\Service\File\FileHelper;
use App\Service\File\FileStorageService;
use App\Service\File\Office\DocumentExportService;
use App\Service\File\Office\DocumentThumbnailDispatcher;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use App\Service\Usage\StorageQuotaService;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * The bytes and the records a WOPI save has to leave behind.
 *
 * The previous document revision is left in place. Its binary hash then no
 * longer matches, so the next chat edit re-imports the file.
 */
final readonly class WopiFileUpdate
{
    public function __construct(
        private EntityManagerInterface $em,
        private DocumentThumbnailDispatcher $thumbnails,
        private VectorStorageFacade $vectors,
        private StorageQuotaService $quota,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    public function refresh(File $file): bool
    {
        try {
            $this->em->refresh($file);
        } catch (EntityNotFoundException $e) {
            $this->logger->warning('WOPI save could not reload the file', [
                'file_id' => $file->getId(),
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    public function rejection(User $user, int $newSize, int $oldSize): ?WopiHttpResult
    {
        if ($newSize > FileStorageService::getMaxFileSize()) {
            return new WopiHttpResult(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, [
                'error' => 'This file is larger than the 128 MB limit.',
            ]);
        }
        $extra = max(0, $newSize - $oldSize);
        if ($extra > $this->quota->getRemainingStorage($user)) {
            return new WopiHttpResult(Response::HTTP_INSUFFICIENT_STORAGE, [
                'error' => 'There is not enough storage left for this save.',
            ]);
        }

        return null;
    }

    public function store(File $file, string $bytes, int $fileId, int $userId): ?WopiHttpResult
    {
        $absolute = $this->absolutePath($file);
        if (null === $absolute) {
            return new WopiHttpResult(Response::HTTP_NOT_FOUND, ['error' => 'File not found']);
        }
        if (!$this->writeAtomically($absolute, $bytes)) {
            $this->logger->error('WOPI PutFile failed to write', ['file_id' => $fileId, 'user_id' => $userId]);

            return new WopiHttpResult(Response::HTTP_INTERNAL_SERVER_ERROR, [
                'error' => 'The file could not be saved.',
            ]);
        }

        $file->setFileSize(strlen($bytes));
        $file->setFileText('');
        $file->setStatus('uploaded');
        $file->setVectorState(File::VECTOR_STATE_PENDING);
        $this->dropThumbnail($file);
        // setStatus() writes time() into updatedAt. Advance after that so two
        // saves in the same second cannot share a WOPI version.
        $now = time();
        $file->setUpdatedAt($now > $file->getUpdatedAt() ? $now : $file->getUpdatedAt() + 1);
        $this->em->flush();
        $this->invalidateExportCache($file);
        $this->dropVectors($file, $userId);
        $this->thumbnails->dispatchIfNeeded($file);
        $this->logger->info('WOPI PutFile', [
            'file_id' => $fileId,
            'user_id' => $userId,
            'bytes' => strlen($bytes),
        ]);

        return null;
    }

    private function dropVectors(File $file, int $userId): void
    {
        $fileId = $file->getId();
        if (null === $fileId) {
            return;
        }
        try {
            $this->vectors->deleteByFile($userId, $fileId);
        } catch (\Throwable $e) {
            $this->logger->warning('WOPI save could not drop the old search index', [
                'file_id' => $fileId,
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function dropThumbnail(File $file): void
    {
        $relative = $file->getThumbPath();
        if (is_string($relative) && '' !== $relative) {
            $path = $this->uploadDir.'/'.ltrim($relative, '/');
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $file->setThumbPath(null);
    }

    private function absolutePath(File $file): ?string
    {
        $relative = $file->getFilePath();
        if ('' === $relative) {
            return null;
        }

        return $this->uploadDir.'/'.ltrim($relative, '/');
    }

    private function writeAtomically(string $absolute, string $bytes): bool
    {
        $directory = dirname($absolute);
        if (!is_dir($directory)) {
            return false;
        }
        $temporary = $absolute.'.wopi-'.bin2hex(random_bytes(4));
        if (false === FileHelper::writeFile($temporary, $bytes)) {
            return false;
        }
        if (!rename($temporary, $absolute)) {
            @unlink($temporary);

            return false;
        }
        FileHelper::setFilePermissions($absolute);

        return true;
    }

    private function invalidateExportCache(File $file): void
    {
        foreach (DocumentExportService::cachedRelativePaths($file->getFilePath()) as $relative) {
            $export = $this->uploadDir.'/'.ltrim($relative, '/');
            if (is_file($export)) {
                @unlink($export);
            }
        }
    }
}
