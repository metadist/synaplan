<?php

declare(strict_types=1);

namespace App\Service\Iam\ResourceKind;

use App\Service\Iam\Permission;

interface ShareableResourceKindInterface
{
    public function key(): string;

    /** null = not found */
    public function ownerId(string $resourceId): ?int;

    /** Name, icon, meta for dialogs/lists — never content. */
    public function describe(string $resourceId): ResourceCard;

    /**
     * @return iterable<ResourceCard>
     */
    public function listOwnedBy(int $userId): iterable;

    /** e.g. invalidate caches after a share change */
    public function onShareChanged(string $resourceId): void;

    /**
     * Subset of read|use|edit|manage this kind can grant to a share subject.
     *
     * @return list<Permission>
     */
    public function supportedPermissions(): array;
}
