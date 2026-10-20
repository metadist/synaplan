<?php

declare(strict_types=1);

namespace App\Service\Iam\Erasure;

use App\Entity\CloudDelivery;
use App\Entity\DocumentRevision;
use App\Entity\File;
use App\Repository\CloudDeliveryRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FileRepository;
use App\Service\File\FileStorageService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/**
 * The user's files and their document revisions. The rows go inside the
 * transaction; the stored files and the now-empty upload directories go
 * after the commit, because a rollback cannot bring deleted bytes back.
 */
#[AsTaggedItem(priority: 120)]
final readonly class FileEraser implements UserDataEraserInterface
{
    public function __construct(
        private EntityManagerInterface $em,
        private FileRepository $files,
        private DocumentRevisionRepository $revisions,
        private CloudDeliveryRepository $deliveries,
        private FileStorageService $fileStorage,
        private LoggerInterface $logger,
    ) {
    }

    public function erase(UserErasure $erasure): void
    {
        $userId = $erasure->userId;
        $this->deliveries->deleteForUser($userId);
        $this->revisions->deleteForUser($userId);

        $paths = [];
        foreach ($this->files->findBy(['userId' => $userId]) as $file) {
            $filePath = $file->getFilePath();
            if ($filePath) {
                $paths[(int) $file->getId()] = $filePath;
            }
            $this->em->remove($file);
        }

        $erasure->afterCommit(fn () => $this->deleteStoredFiles($userId, $paths));
        $erasure->afterCommit(fn () => $this->removeEmptyUserDirectories($userId));
    }

    public static function covers(): array
    {
        return [File::class, DocumentRevision::class, CloudDelivery::class];
    }

    /**
     * @param array<int, string> $paths file id => path relative to the upload directory
     */
    private function deleteStoredFiles(int $userId, array $paths): void
    {
        $deletedCount = 0;
        $failedCount = 0;
        foreach ($paths as $fileId => $filePath) {
            $context = ['user_id' => $userId, 'file_id' => $fileId, 'path' => $filePath];
            try {
                if ($this->fileStorage->deleteFile($filePath)) {
                    ++$deletedCount;
                    $this->logger->debug('Physical file deleted', $context);
                } else {
                    ++$failedCount;
                    $this->logger->warning('Physical file not found or already deleted', $context);
                }
            } catch (\Throwable $e) {
                ++$failedCount;
                $this->logger->error('Failed to delete physical file', $context + ['exception' => $e]);
            }
        }

        if ($deletedCount > 0 || $failedCount > 0) {
            $this->logger->info('File deletion completed', [
                'user_id' => $userId,
                'total_files' => count($paths),
                'deleted' => $deletedCount,
                'failed' => $failedCount,
            ]);
        }
    }

    /**
     * Best effort: never fails the deletion. Layout under var/uploads:
     * {last2}/{prev3}/{paddedUserId}/{year}/{month}/.
     */
    private function removeEmptyUserDirectories(int $userId): void
    {
        try {
            $userDir = $this->fileStorage->getUserBaseAbsolutePath($userId);
            if (!is_dir($userDir)) {
                return;
            }

            $yearDirs = glob($userDir.'/*', GLOB_ONLYDIR);
            if (!$yearDirs) {
                @rmdir($userDir);

                return;
            }

            foreach ($yearDirs as $yearDir) {
                foreach (glob($yearDir.'/*', GLOB_ONLYDIR) ?: [] as $monthDir) {
                    @rmdir($monthDir);
                }
                @rmdir($yearDir);
            }

            @rmdir($userDir);
            $level2Dir = dirname($userDir);
            @rmdir($level2Dir);
            @rmdir(dirname($level2Dir));

            $this->logger->debug('User directory cleanup completed', ['user_id' => $userId, 'path' => $userDir]);
        } catch (\Throwable $e) {
            $this->logger->debug('User directory cleanup failed (non-critical)', ['user_id' => $userId, 'exception' => $e]);
        }
    }
}
