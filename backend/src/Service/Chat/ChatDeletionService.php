<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use App\Repository\ChatSummaryRepository;
use App\Repository\FileRepository;
use App\Repository\MessageRepository;
use App\Repository\ShareRepository;
use App\Service\Digest\MessageDigestMaintenance;
use App\Service\File\FileStorageService;
use App\Service\File\OgImageService;
use App\Service\Iam\ResourceKind\ConversationKind;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Deletes a chat and the conversation it owned: messages, metadata, plan
 * rows, message-bound files, summary, digests and shares.
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
            $legacyPath = $message->getFilePath();
            if ('' !== $legacyPath) {
                $this->fileStorage->deleteFile($legacyPath);
            }
        }

        if ([] !== $messageIds) {
            $files = $this->fileRepository->findFilesByMessageIds($userId, $messageIds, 10_000);
            foreach ($files as $file) {
                $path = $file->getFilePath();
                if ('' !== $path) {
                    $this->fileStorage->deleteFile($path);
                }
                $this->em->remove($file);
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
}
