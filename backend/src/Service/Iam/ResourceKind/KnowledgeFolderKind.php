<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Repository\FileRepository;
use App\Service\Iam\Permission;

/**
 * Knowledge folder identity is (ownerId, groupKey) on BFILES.
 * Resource id format: "{ownerId}:{groupKey}".
 */
final readonly class KnowledgeFolderKind implements ShareableResourceKindInterface
{
    public const KEY = 'knowledge_folder';

    public function __construct(
        private FileRepository $fileRepository,
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function ownerId(string $resourceId): ?int
    {
        $parsed = self::parseId($resourceId);
        if (null === $parsed) {
            return null;
        }

        [$ownerId, $groupKey] = $parsed;
        if (!$this->fileRepository->existsForUserAndGroupKey($ownerId, $groupKey)) {
            return null;
        }

        return $ownerId;
    }

    public function describe(string $resourceId): ResourceCard
    {
        $parsed = self::parseId($resourceId);
        if (null === $parsed) {
            return new ResourceCard($resourceId, $resourceId, 'folder');
        }

        [$ownerId, $groupKey] = $parsed;
        $counts = $this->fileRepository->getGroupCountsByUser($ownerId);

        return new ResourceCard(
            $resourceId,
            $groupKey,
            'folder',
            ['fileCount' => $counts[$groupKey] ?? 0, 'ownerId' => $ownerId],
        );
    }

    public function listOwnedBy(int $userId): iterable
    {
        foreach ($this->fileRepository->getGroupCountsByUser($userId) as $groupKey => $count) {
            $id = self::resourceId($userId, (string) $groupKey);
            yield new ResourceCard($id, (string) $groupKey, 'folder', ['fileCount' => $count, 'ownerId' => $userId]);
        }
    }

    public function onShareChanged(string $resourceId): void
    {
    }

    public function supportedPermissions(): array
    {
        return [Permission::Read, Permission::Use, Permission::Edit, Permission::Manage];
    }

    public static function resourceId(int $ownerId, string $groupKey): string
    {
        return $ownerId.':'.$groupKey;
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    public static function parseId(string $resourceId): ?array
    {
        $pos = strpos($resourceId, ':');
        if (false === $pos) {
            return null;
        }

        $ownerRaw = substr($resourceId, 0, $pos);
        $groupKey = substr($resourceId, $pos + 1);
        if ('' === $ownerRaw || '' === $groupKey || !ctype_digit($ownerRaw)) {
            return null;
        }

        return [(int) $ownerRaw, $groupKey];
    }
}
