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

    /**
     * Refuse a grant on a resource that exists but is not in a shareable
     * state (draft, archived, owned by another kind). Owner and permission
     * checks happen in {@see \App\Service\Iam\ShareService}; this is the
     * kind's own veto.
     *
     * @throws \App\Service\Iam\Exception\ShareNotAllowedException
     */
    public function assertShareable(string $resourceId): void;
}
