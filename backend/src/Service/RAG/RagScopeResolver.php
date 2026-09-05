<?php

declare(strict_types=1);

namespace App\Service\RAG;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Share;
use App\Repository\ChatRepository;
use App\Repository\FileRepository;
use App\Repository\GroupMemberRepository;
use App\Repository\MessageRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\ConversationKind;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;
use App\Service\RAG\VectorStorage\DTO\RagScope;

/**
 * Own files plus knowledge that is shared with this user at "Can use" or higher.
 */
final readonly class RagScopeResolver
{
    public const PICKER_PREFIX = 'shared:';
    public const SHARED_FILE_REF = 'shared_file_ref';

    public function __construct(
        private IamConfig $iamConfig,
        private ShareRepository $shareRepository,
        private GroupMemberRepository $groupMemberRepository,
        private ChatRepository $chatRepository,
        private MessageRepository $messageRepository,
        private FileRepository $fileRepository,
    ) {
    }

    /**
     * @return list<RagScope>
     */
    public function resolve(int $userId, ?string $groupKey): array
    {
        $picker = self::parseSharedPicker($groupKey);
        if (null !== $picker) {
            if (!$this->iamConfig->isSharingEnabled($userId)
                || !$this->canUseFolder($userId, $picker[0], $picker[1])
            ) {
                return [new RagScope($userId, null)];
            }

            return [new RagScope($picker[0], $picker[1])];
        }

        $own = new RagScope($userId, $groupKey);
        if (!$this->iamConfig->isSharingEnabled($userId)) {
            return [$own];
        }

        $scopes = [$own];
        foreach ($this->sharedFolderScopes($userId, $groupKey) as $scope) {
            $scopes[] = $scope;
        }
        foreach ($this->sharedConversationFileScopes($userId, $groupKey) as $scope) {
            $scopes[] = $scope;
        }

        return $this->dedupe($scopes);
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    public static function parseSharedPicker(?string $groupKey): ?array
    {
        if (null === $groupKey || !str_starts_with($groupKey, self::PICKER_PREFIX)) {
            return null;
        }
        $rest = substr($groupKey, strlen(self::PICKER_PREFIX));
        $pos = strpos($rest, ':');
        if (false === $pos) {
            return null;
        }
        $ownerRaw = substr($rest, 0, $pos);
        $folder = substr($rest, $pos + 1);
        if ('' === $ownerRaw || '' === $folder || !ctype_digit($ownerRaw)) {
            return null;
        }

        return [(int) $ownerRaw, $folder];
    }

    /**
     * @return list<RagScope>
     */
    private function sharedFolderScopes(int $userId, ?string $groupKey): array
    {
        $out = [];
        foreach ($this->sharesReaching($userId, KnowledgeFolderKind::KEY) as $share) {
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission || !$permission->implies(Permission::Use)) {
                continue;
            }
            $parsed = KnowledgeFolderKind::parseId($share->getResourceId());
            if (null === $parsed) {
                continue;
            }
            [$ownerId, $folderKey] = $parsed;
            if (null !== $groupKey && $groupKey !== $folderKey) {
                continue;
            }
            $out[] = new RagScope($ownerId, $folderKey);
        }

        return $out;
    }

    /**
     * Files from conversations shared with "Can use", plus file refs on my copies.
     *
     * @return list<RagScope>
     */
    private function sharedConversationFileScopes(int $userId, ?string $groupKey): array
    {
        $byOwnerFolder = [];
        $loose = [];

        foreach ($this->sharesReaching($userId, ConversationKind::KEY) as $share) {
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission || !$permission->implies(Permission::Use)) {
                continue;
            }
            if (!ctype_digit($share->getResourceId())) {
                continue;
            }
            $chat = $this->chatRepository->find((int) $share->getResourceId());
            if (null === $chat) {
                continue;
            }
            $this->collectChatFiles((int) $chat->getId(), $byOwnerFolder, $loose);
        }

        $this->collectReferencedFiles($userId, $byOwnerFolder, $loose);

        $scopes = [];
        foreach ($byOwnerFolder as $key => $fileIds) {
            [$ownerId, $folder] = explode("\0", $key, 2);
            if (null !== $groupKey && $groupKey !== $folder) {
                continue;
            }
            $scopes[] = new RagScope((int) $ownerId, $folder);
        }
        foreach ($loose as $ownerId => $fileIds) {
            if (null !== $groupKey) {
                continue;
            }
            $scopes[] = new RagScope((int) $ownerId, null, array_values(array_unique($fileIds)));
        }

        return $scopes;
    }

    /**
     * @param array<string, list<int>> $byOwnerFolder
     * @param array<int, list<int>>    $loose
     */
    private function collectChatFiles(int $chatId, array &$byOwnerFolder, array &$loose): void
    {
        /** @var list<Message> $messages */
        $messages = $this->messageRepository->findBy(['chatId' => $chatId]);
        foreach ($messages as $message) {
            foreach ($message->getFiles() as $file) {
                $this->indexFile($file, $byOwnerFolder, $loose);
            }
            $messageId = $message->getId();
            if (null === $messageId) {
                continue;
            }
            foreach ($this->fileRepository->findBy(['messageId' => $messageId]) as $file) {
                $this->indexFile($file, $byOwnerFolder, $loose);
            }
        }
    }

    /**
     * @param array<string, list<int>> $byOwnerFolder
     * @param array<int, list<int>>    $loose
     */
    private function collectReferencedFiles(int $userId, array &$byOwnerFolder, array &$loose): void
    {
        /** @var list<Message> $messages */
        $messages = $this->messageRepository->findBy(['userId' => $userId]);
        foreach ($messages as $message) {
            $raw = $message->getMeta(self::SHARED_FILE_REF);
            if (null === $raw || '' === $raw) {
                continue;
            }
            foreach (explode(',', $raw) as $part) {
                $fileId = (int) trim($part);
                if ($fileId <= 0) {
                    continue;
                }
                $file = $this->fileRepository->find($fileId);
                if ($file instanceof File) {
                    $this->indexFile($file, $byOwnerFolder, $loose);
                }
            }
        }
    }

    /**
     * @param array<string, list<int>> $byOwnerFolder
     * @param array<int, list<int>>    $loose
     */
    private function indexFile(File $file, array &$byOwnerFolder, array &$loose): void
    {
        $id = (int) $file->getId();
        $ownerId = $file->getUserId();
        $folder = $file->getGroupKey();
        if (null !== $folder && '' !== $folder) {
            $byOwnerFolder[$ownerId."\0".$folder][] = $id;

            return;
        }
        $loose[$ownerId][] = $id;
    }

    private function canUseFolder(int $userId, int $ownerId, string $groupKey): bool
    {
        $resourceId = KnowledgeFolderKind::resourceId($ownerId, $groupKey);
        foreach ($this->sharesReaching($userId, KnowledgeFolderKind::KEY) as $share) {
            if ($share->getResourceId() !== $resourceId) {
                continue;
            }
            $permission = Permission::tryFrom($share->getPermission());
            if (null !== $permission && $permission->implies(Permission::Use)) {
                return true;
            }
        }

        return $ownerId === $userId;
    }

    /**
     * @return list<Share>
     */
    private function sharesReaching(int $userId, string $kind): array
    {
        $groupIds = array_map(
            static fn ($m): int => $m->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );

        return $this->shareRepository->findForSubjects($userId, $groupIds, $kind);
    }

    /**
     * @param list<RagScope> $scopes
     *
     * @return list<RagScope>
     */
    private function dedupe(array $scopes): array
    {
        $seen = [];
        $out = [];
        foreach ($scopes as $scope) {
            $key = $scope->ownerId.'|'.($scope->groupKey ?? '').'|'.implode(',', $scope->fileIds);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $scope;
        }

        return $out;
    }
}
