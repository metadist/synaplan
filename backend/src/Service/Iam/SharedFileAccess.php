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

        $messageId = $file->getMessageId();
        if (null !== $messageId) {
            $message = $this->messageRepository->find($messageId);
            $chatId = $message?->getChatId();
            if (null !== $chatId
                && $this->accessGate->decide($user, ConversationKind::KEY, (string) $chatId, Permission::Read)
            ) {
                return true;
            }
        }

        return $this->hasSharedFileRef((int) $user->getId(), (int) $file->getId());
    }

    /**
     * True when a copy I own still points at this (now missing) owner file.
     */
    public function isMissingReferencedFile(User $user, int $fileId): bool
    {
        if ($fileId <= 0 || $this->fileRepository->find($fileId) instanceof File) {
            return false;
        }

        return $this->hasSharedFileRef((int) $user->getId(), $fileId);
    }

    private function hasSharedFileRef(int $userId, int $fileId): bool
    {
        return $this->messageMetaRepository->userHasSharedFileRef($userId, $fileId);
    }
}
