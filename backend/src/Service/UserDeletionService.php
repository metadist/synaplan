<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\User;
use App\Repository\ApiKeyRepository;
use App\Repository\ChatRepository;
use App\Repository\EmailVerificationAttemptRepository;
use App\Repository\FileRepository;
use App\Repository\InboundEmailHandlerRepository;
use App\Repository\MessageRepository;
use App\Repository\SessionRepository;
use App\Repository\TokenRepository;
use App\Repository\UseLogRepository;
use App\Repository\VerificationTokenRepository;
use App\Repository\WidgetRepository;
use App\Service\File\FileStorageService;
use App\Service\RAG\VectorStorage\VectorStorageFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class UserDeletionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private VerificationTokenRepository $verificationTokenRepository,
        private TokenRepository $tokenRepository,
        private ApiKeyRepository $apiKeyRepository,
        private SessionRepository $sessionRepository,
        private UseLogRepository $useLogRepository,
        private WidgetRepository $widgetRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private EmailVerificationAttemptRepository $emailVerificationAttemptRepository,
        private FileRepository $fileRepository,
        private InboundEmailHandlerRepository $inboundEmailHandlerRepository,
        private FileStorageService $fileStorageService,
        private VectorStorageFacade $vectorStorageFacade,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Delete user and all related data atomically.
     *
     * @throws \Exception if deletion fails
     */
    public function deleteUser(User $user): void
    {
        $userId = $user->getId();
        $email = $user->getMail();

        $this->logger->info('User deletion initiated', [
            'user_id' => $userId,
            'email' => $email,
        ]);

        // Use transaction for atomic deletion
        $this->em->getConnection()->beginTransaction();

        try {
            // Delete all related entities to avoid foreign key constraint violations
            $this->deleteVerificationTokens($userId);
            $this->deleteAuthTokens($userId);
            $this->deleteApiKeys($userId);
            $this->deleteSessions($userId);
            $this->deleteRagDocuments($userId);
            $this->deleteUseLogs($userId);
            $this->deleteWidgets($userId);
            $this->deleteChats($userId);
            $this->deleteMessages($userId);
            $this->deleteEmailVerificationAttempts($email);
            $this->deleteFiles($userId);
            $this->deleteInboundEmailHandlers($userId);

            // Finally, delete the user account
            $this->em->remove($user);
            $this->em->flush();

            $this->em->getConnection()->commit();

            // Cleanup empty user directories (best effort, outside transaction)
            $this->cleanupUserDirectories($userId);

            $this->logger->info('User and all related data deleted successfully', [
                'user_id' => $userId,
                'email' => $email,
            ]);
        } catch (\Throwable $e) {
            $this->em->getConnection()->rollBack();

            $this->logger->error('Failed to delete user', [
                'user_id' => $userId,
                'email' => $email,
                'error' => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }

    private function deleteVerificationTokens(int $userId): void
    {
        $tokens = $this->verificationTokenRepository->findBy(['userId' => $userId]);
        foreach ($tokens as $token) {
            $this->em->remove($token);
        }
    }

    private function deleteAuthTokens(int $userId): void
    {
        $tokens = $this->tokenRepository->findBy(['userId' => $userId]);
        foreach ($tokens as $token) {
            $this->em->remove($token);
        }
    }

    private function deleteApiKeys(int $userId): void
    {
        $apiKeys = $this->apiKeyRepository->findBy(['ownerId' => $userId]);
        foreach ($apiKeys as $apiKey) {
            $this->em->remove($apiKey);
        }
    }

    private function deleteSessions(int $userId): void
    {
        $sessions = $this->sessionRepository->findBy(['userId' => $userId]);
        foreach ($sessions as $session) {
            $this->em->remove($session);
        }
    }

    private function deleteRagDocuments(int $userId): void
    {
        // Delete all RAG documents via facade
        $this->vectorStorageFacade->deleteAllForUser($userId);
    }

    private function deleteUseLogs(int $userId): void
    {
        $useLogs = $this->useLogRepository->findBy(['userId' => $userId]);
        foreach ($useLogs as $useLog) {
            $this->em->remove($useLog);
        }
    }

    private function deleteWidgets(int $userId): void
    {
        $widgets = $this->widgetRepository->findBy(['ownerId' => $userId]);
        foreach ($widgets as $widget) {
            $this->em->remove($widget);
        }
    }

    private function deleteChats(int $userId): void
    {
        $chats = $this->chatRepository->findBy(['userId' => $userId]);
        foreach ($chats as $chat) {
            $this->em->remove($chat);
        }
    }

    private function deleteMessages(int $userId): void
    {
        $messages = $this->messageRepository->findBy(['userId' => $userId]);
        foreach ($messages as $message) {
            $this->em->remove($message);
        }
    }

    private function deleteEmailVerificationAttempts(string $email): void
    {
        $attempts = $this->emailVerificationAttemptRepository->findBy(['email' => $email]);
        foreach ($attempts as $attempt) {
            $this->em->remove($attempt);
        }
    }

    private function deleteFiles(int $userId): void
    {
        $files = $this->fileRepository->findBy(['userId' => $userId]);
        $deletedCount = 0;
        $failedCount = 0;

        foreach ($files as $file) {
            $filePath = $file->getFilePath();

            // Delete physical file from storage
            if ($filePath) {
                try {
                    $deleted = $this->fileStorageService->deleteFile($filePath);
                    if ($deleted) {
                        ++$deletedCount;
                        $this->logger->debug('Physical file deleted', [
                            'user_id' => $userId,
                            'file_id' => $file->getId(),
                            'path' => $filePath,
                        ]);
                    } else {
                        ++$failedCount;
                        $this->logger->warning('Physical file not found or already deleted', [
                            'user_id' => $userId,
                            'file_id' => $file->getId(),
                            'path' => $filePath,
                        ]);
                    }
                } catch (\Throwable $e) {
                    ++$failedCount;
                    $this->logger->error('Failed to delete physical file', [
                        'user_id' => $userId,
                        'file_id' => $file->getId(),
                        'path' => $filePath,
                        'exception' => $e,
                    ]);
                }
            }

            // Delete database record
            $this->em->remove($file);
        }

        if ($deletedCount > 0 || $failedCount > 0) {
            $this->logger->info('File deletion completed', [
                'user_id' => $userId,
                'total_files' => count($files),
                'deleted' => $deletedCount,
                'failed' => $failedCount,
            ]);
        }
    }

    private function deleteInboundEmailHandlers(int $userId): void
    {
        $handlers = $this->inboundEmailHandlerRepository->findBy(['userId' => $userId]);
        foreach ($handlers as $handler) {
            $this->em->remove($handler);
        }
    }

    /**
     * Cleanup empty user directories after file deletion.
     * This is done outside the transaction as it's a best-effort cleanup.
     * Structure (under var/uploads): {last2}/{prev3}/{paddedUserId}/{year}/{month}/.
     */
    private function cleanupUserDirectories(int $userId): void
    {
        try {
            $userDir = $this->fileStorageService->getUserBaseAbsolutePath($userId);

            // Only proceed if user directory exists
            if (!is_dir($userDir)) {
                return;
            }

            // Get all year directories
            $yearDirs = glob($userDir.'/*', GLOB_ONLYDIR);
            if (!$yearDirs) {
                // Try to remove user dir if empty
                @rmdir($userDir);

                return;
            }

            foreach ($yearDirs as $yearDir) {
                // Get all month directories
                $monthDirs = glob($yearDir.'/*', GLOB_ONLYDIR);
                if ($monthDirs) {
                    foreach ($monthDirs as $monthDir) {
                        // Remove month directory if empty
                        @rmdir($monthDir);
                    }
                }

                // Remove year directory if empty
                @rmdir($yearDir);
            }

            // Remove user directory if empty
            @rmdir($userDir);

            // Remove hashed parent directories if empty (best effort)
            $level2Dir = dirname($userDir);
            @rmdir($level2Dir);
            $level1Dir = dirname($level2Dir);
            @rmdir($level1Dir);

            $this->logger->debug('User directory cleanup completed', [
                'user_id' => $userId,
                'path' => $userDir,
            ]);
        } catch (\Throwable $e) {
            // Silently ignore errors in directory cleanup
            // This is best-effort and should not fail the deletion
            $this->logger->debug('User directory cleanup failed (non-critical)', [
                'user_id' => $userId,
                'exception' => $e,
            ]);
        }
    }
}
