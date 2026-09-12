<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Chat;
use App\Entity\File;
use App\Repository\ChatRepository;
use App\Repository\ChatSummaryRepository;
use App\Repository\DocumentRevisionRepository;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\ShareRepository;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\File\FileStorageService;
use App\Service\File\OgImageService;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\RAG\VectorStorage\VectorStorageInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a chat and the conversation it owned: messages, metadata, plan
 * rows, summary, digests and shares.
 *
 * Files are treated by ownership, not by where they entered (#1826): a file
 * attached to or generated in a chat is an ordinary entry in the user's file
 * manager, so it is detached (BMESSAGEID = NULL) and kept on disk. Only
 * ephemeral files — incognito sessions — belong to the conversation itself
 * and are removed together with their vectors and revisions.
 *
 * The BMESSAGES.BCHATID FK is ON DELETE SET NULL, so removing the chat row
 * alone detaches the conversation instead of deleting it (#1811).
 */
final readonly class ChatDeletionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private ChatRepository $chatRepository,
        private ChatSummaryRepository $chatSummaryRepository,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
        private FileStorageService $fileStorage,
        private MessageDigestMaintenance $digestMaintenance,
        private ShareRepository $shareRepository,
        private OgImageService $ogImageService,
        private VectorStorageInterface $vectorStorage,
        private DocumentRevisionRepository $documentRevisions,
    ) {
    }

    public function deleteOwnedChat(int $userId, Chat $chat): void
    {
        if ($chat->getUserId() !== $userId) {
            return;
        }

        $this->purgeConversation($userId, $chat);
        $this->em->flush();
    }

    /**
     * @param list<int> $chatIds
     */
    public function deleteOwnedChats(int $userId, array $chatIds): void
    {
        $seen = [];
        foreach ($chatIds as $chatId) {
            $id = (int) $chatId;
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $chat = $this->chatRepository->find($id);
            if (!$chat instanceof Chat || $chat->getUserId() !== $userId) {
                continue;
            }
            $this->purgeConversation($userId, $chat);
        }
        $this->em->flush();
    }

    private function purgeConversation(int $userId, Chat $chat): void
    {
        $chatId = (int) $chat->getId();
        $messages = $this->messageRepository->findAllByChatId($userId, $chatId);
        $messageIds = [];
        foreach ($messages as $message) {
            $id = $message->getId();
            if (null !== $id) {
                $messageIds[] = (int) $id;
            }
        }

        $keptPaths = $this->releaseFiles($userId, $messageIds);

        // Generated media rides the legacy path channel on the message and
        // shares its disk file with the BFILES row that was just kept, so the
        // legacy copy is only removed when no library file still points at it.
        foreach ($messages as $message) {
            $legacyPath = $message->getFilePath();
            if ('' !== $legacyPath && !isset($keptPaths[$legacyPath])) {
                $this->fileStorage->deleteFile($legacyPath);
            }
        }

        // Meta first — BMESSAGEMETA has no ON DELETE CASCADE. Plan rows on
        // BMESSAGE_TASKS cascade with the message delete that follows.
        $this->messageRepository->deleteByChatIds([$chatId]);

        $this->chatSummaryRepository->deleteByChatId($chatId);
        $this->digestMaintenance->deactivateForChat($userId, $chatId);
        $this->shareRepository->deleteByResource(ConversationKind::KEY, (string) $chatId);
        $this->ogImageService->deleteOgImage($chat);
        $this->em->remove($chat);
    }

    /**
     * Detach the user's library files from the conversation and remove the
     * session-only (ephemeral) ones.
     *
     * @param list<int> $messageIds
     *
     * @return array<string, true> disk paths of files that stay in the library
     */
    private function releaseFiles(int $userId, array $messageIds): array
    {
        if ([] === $messageIds) {
            return [];
        }

        $keptPaths = [];
        foreach ($this->fileRepository->findAllFilesByMessageIds($userId, $messageIds) as $file) {
            if ($file->isEphemeral()) {
                $this->removeEphemeralFile($userId, $file);
                continue;
            }

            $file->setMessageId(null);
            $path = $file->getFilePath();
            if ('' !== $path) {
                $keptPaths[$path] = true;
            }
        }

        return $keptPaths;
    }

    private function removeEphemeralFile(int $userId, File $file): void
    {
        $fileId = $file->getId();
        if (null !== $fileId) {
            $this->documentRevisions->deleteForFile((int) $fileId);
            $this->vectorStorage->deleteByFile($userId, (int) $fileId);
        }

        $path = $file->getFilePath();
        if ('' !== $path) {
            $this->fileStorage->deleteFile($path);
        }
        $this->em->remove($file);
    }
}
