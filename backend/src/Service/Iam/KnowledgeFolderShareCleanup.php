<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Repository\FileRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;

/**
 * A knowledge folder has no row of its own — it exists while at least one
 * non-ephemeral file carries its group key. Its shares therefore have no
 * cascade to ride on: once the last file leaves, the BSHARES rows would stay
 * behind and silently apply to the next folder created under the same name.
 *
 * Call {@see self::forgetIfEmpty()} after a file is deleted or moved out of
 * a folder; it removes the shares only when the folder is actually gone.
 */
final readonly class KnowledgeFolderShareCleanup
{
    public function __construct(
        private FileRepository $fileRepository,
        private ShareRepository $shareRepository,
    ) {
    }

    public function forgetIfEmpty(int $ownerId, ?string $groupKey): void
    {
        if (null === $groupKey || '' === $groupKey) {
            return;
        }
        if ($this->fileRepository->existsForUserAndGroupKey($ownerId, $groupKey)) {
            return;
        }

        $this->shareRepository->deleteByResource(
            KnowledgeFolderKind::KEY,
            KnowledgeFolderKind::resourceId($ownerId, $groupKey),
        );
    }
}
