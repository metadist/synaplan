<?php

declare(strict_types=1);

namespace App\Service\SmartSearch\Index\Source;

use App\Entity\File;
use App\Entity\User;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Iam\Permission;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;
use App\Service\Iam\SharedFileAccess;
use App\Service\Iam\SharedResourceIds;
use App\Service\SmartSearch\Index\ResolvedItem;
use App\Service\SmartSearch\Index\SearchDocument;
use App\Service\SmartSearch\Index\SearchDocumentSourceInterface;

/**
 * Files: the display name plus the start of the extracted text. Deep matches
 * inside long documents come from the RAG chunks in the semantic tier.
 * Files in knowledge folders shared with the user are found in the owner's
 * rows and shown with the owner's name.
 */
final readonly class FileDocumentSource implements SearchDocumentSourceInterface
{
    public const KIND = 'file';
    private const TRACKED_FIELDS = ['fileName', 'originalName', 'groupKey', 'ephemeral'];
    private const DEFAULT_GROUP = 'DEFAULT';
    /** Characters of the extracted text the index reads (the body is cut to MAX_BODY_LENGTH after normalising). */
    private const TEXT_CHARS = SearchDocument::MAX_BODY_LENGTH * 2;

    /** Bounds the `IN (...)` list a search adds for shared files. */
    private const MAX_SHARED = 500;

    public function __construct(
        private FileRepository $files,
        private SharedResourceIds $sharedIds,
        private SharedFileAccess $sharedFileAccess,
        private UserRepository $users,
    ) {
    }

    public function kind(): string
    {
        return self::KIND;
    }

    public function isEnabledFor(int $userId): bool
    {
        return true;
    }

    public function refFor(object $entity, array $changeSet): ?array
    {
        if (!$entity instanceof File) {
            return null;
        }
        if ([] !== $changeSet && [] === array_intersect(self::TRACKED_FIELDS, array_keys($changeSet)) && !self::indexedTextChanged($changeSet)) {
            return null;
        }
        $id = $entity->getId();

        return null === $id ? null : ['userId' => $entity->getUserId(), 'refId' => (string) $id];
    }

    public function build(int $userId, string $refId): ?SearchDocument
    {
        $file = $this->files->find((int) $refId);
        if (!$file instanceof File || $file->getUserId() !== $userId) {
            return null;
        }

        return $this->document($file);
    }

    public function allForUser(int $userId): iterable
    {
        foreach ($this->files->findSearchRowsForUser($userId, self::TEXT_CHARS) as $row) {
            yield new SearchDocument(
                userId: $userId,
                kind: self::KIND,
                refId: (string) $row['id'],
                title: self::displayNameOf($row['originalName'], $row['fileName']),
                body: $row['text'],
                updated: $row['updatedAt'],
            );
        }
    }

    public function sharedRefIds(int $userId): array
    {
        $folders = [];
        foreach ($this->sharedIds->rawForUser($userId, KnowledgeFolderKind::KEY, Permission::Read) as $resourceId) {
            $folder = KnowledgeFolderKind::parseId($resourceId);
            if (null !== $folder && $folder[0] !== $userId) {
                $folders[] = $folder;
            }
        }

        return array_map('strval', $this->files->findIdsInFolders($folders, self::MAX_SHARED));
    }

    public function resolve(int $userId, array $refIds): array
    {
        $ids = array_values(array_filter(array_map('intval', $refIds), static fn (int $id): bool => $id > 0));
        if ([] === $ids) {
            return [];
        }

        $resolved = [];
        foreach ($this->files->findBy(['userId' => $userId, 'id' => $ids, 'ephemeral' => false]) as $file) {
            $resolved[(string) $file->getId()] = $this->item($file);
        }

        $foreign = array_values(array_diff($ids, array_map('intval', array_keys($resolved))));
        $user = [] === $foreign ? null : $this->users->find($userId);
        if (!$user instanceof User) {
            return $resolved;
        }
        $shared = array_values(array_filter(
            $this->files->findBy(['id' => $foreign, 'ephemeral' => false]),
            fn (File $file): bool => $this->sharedFileAccess->canRead($user, $file),
        ));
        $owners = $this->ownerNames(array_map(static fn (File $file): int => $file->getUserId(), $shared));
        foreach ($shared as $file) {
            $resolved[(string) $file->getId()] = $this->item($file, $owners[$file->getUserId()] ?? '');
        }

        return $resolved;
    }

    private function item(File $file, ?string $sharedBy = null): ResolvedItem
    {
        $id = (string) $file->getId();
        $group = trim((string) $file->getGroupKey());

        return new ResolvedItem(
            title: $this->displayName($file),
            route: '/files?file='.$id,
            subtitle: '' === $group || self::DEFAULT_GROUP === $group ? null : $group,
            sharedBy: $sharedBy,
        );
    }

    /**
     * @param list<int> $ownerIds
     *
     * @return array<int, string>
     */
    private function ownerNames(array $ownerIds): array
    {
        $names = [];
        foreach ([] === $ownerIds ? [] : $this->users->findBy(['id' => array_values(array_unique($ownerIds))]) as $owner) {
            $names[(int) $owner->getId()] = $owner->getDisplayName();
        }

        return $names;
    }

    private function document(File $file): ?SearchDocument
    {
        $id = $file->getId();
        if (null === $id || $file->isEphemeral()) {
            return null;
        }

        return new SearchDocument(
            userId: $file->getUserId(),
            kind: self::KIND,
            refId: (string) $id,
            title: $this->displayName($file),
            body: mb_substr($file->getFileText(), 0, self::TEXT_CHARS),
            updated: $file->getUpdatedAt(),
        );
    }

    private function displayName(File $file): string
    {
        return self::displayNameOf($file->getOriginalName(), $file->getFileName());
    }

    private static function displayNameOf(?string $originalName, string $fileName): string
    {
        $original = trim((string) $originalName);

        return '' !== $original ? $original : $fileName;
    }

    /**
     * Extraction flushes the text several times; only a change inside the
     * part the index reads makes the row stale.
     *
     * @param array<string, array{0: mixed, 1: mixed}> $changeSet
     */
    private static function indexedTextChanged(array $changeSet): bool
    {
        if (!array_key_exists('fileText', $changeSet)) {
            return false;
        }
        [$old, $new] = $changeSet['fileText'];

        return mb_substr((string) $old, 0, self::TEXT_CHARS) !== mb_substr((string) $new, 0, self::TEXT_CHARS);
    }
}
