<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\MessageMetaRepository;
use App\Repository\MessageRepository;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;

/**
 * Read access to a file I own, or one that reaches me through a share.
 *
 * Access follows the *live* share only. A copy made with "continue as copy"
 * remembers the owner's file ids ({@see \App\Service\RAG\RagScopeResolver::SHARED_FILE_REF}),
 * but that memory never grants access by itself — revoking the conversation
 * or folder share closes the file again.
 */
final readonly class SharedFileAccess
{
    public function __construct(
        private AccessGate $accessGate,
        private IamConfig $iamConfig,
        private MessageRepository $messageRepository,
        private MessageMetaRepository $messageMetaRepository,
        private FileRepository $fileRepository,
    ) {
    }

    public function canRead(User $user, File $file): bool
    {
        if ($file->getUserId() === (int) $user->getId()) {
            return true;
        }
        if (!$this->iamConfig->isSharingEnabled((int) $user->getId())) {
            return false;
        }

        $groupKey = $file->getGroupKey();
        if (null !== $groupKey && '' !== $groupKey) {
            $folderId = KnowledgeFolderKind::resourceId($file->getUserId(), $groupKey);
            if ($this->accessGate->decide($user, KnowledgeFolderKind::KEY, $folderId, Permission::Read)) {
                return true;
            }
        }

        foreach ($this->chatIdsCarrying($file) as $chatId) {
            if ($this->accessGate->decide($user, ConversationKind::KEY, (string) $chatId, Permission::Read)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<int>
     */
    private function chatIdsCarrying(File $file): array
    {
        $chatIds = [];
        $messageId = $file->getMessageId();
        if (null !== $messageId) {
            $chatId = $this->messageRepository->find($messageId)?->getChatId();
            if (null !== $chatId) {
                $chatIds[] = $chatId;
            }
        }
        $fileId = $file->getId();
        if (null !== $fileId) {
            foreach ($this->messageRepository->findChatIdsByFileId((int) $fileId) as $chatId) {
                $chatIds[] = $chatId;
            }
        }

        return array_values(array_unique($chatIds));
    }

    /**
     * True when a copy I own still points at this (now missing) owner file.
     * Only reveals that a file id I was once given no longer exists (410).
     */
    public function isMissingReferencedFile(User $user, int $fileId): bool
    {
        if ($fileId <= 0 || $this->fileRepository->find($fileId) instanceof File) {
            return false;
        }

        return $this->messageMetaRepository->userHasSharedFileRef((int) $user->getId(), $fileId);
    }
}
