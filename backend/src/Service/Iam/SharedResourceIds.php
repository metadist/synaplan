<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;

/**
 * Numeric BSHARES resource ids that reach this user at a given permission.
 * Returns [] when sharing is off so callers keep their legacy queries.
 */
final readonly class SharedResourceIds
{
    public function __construct(
        private IamConfig $iamConfig,
        private ShareRepository $shareRepository,
        private GroupMemberRepository $groupMemberRepository,
    ) {
    }

    /**
     * @return list<int>
     */
    public function forUser(int $userId, string $kind, Permission $needed = Permission::Use): array
    {
        if (!$this->iamConfig->isSharingEnabled($userId)) {
            return [];
        }

        $groupIds = array_map(
            static fn ($member): int => $member->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );

        $ids = [];
        foreach ($this->shareRepository->findForSubjects($userId, $groupIds, $kind) as $share) {
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission || !$permission->implies($needed)) {
                continue;
            }
            if (!ctype_digit($share->getResourceId())) {
                continue;
            }
            $ids[] = (int) $share->getResourceId();
        }

        return array_values(array_unique($ids));
    }

    /**
     * Resource ids for every kind whose key starts with $prefix (e.g. "synaform:").
     *
     * @return list<int>
     */
    public function forUserKindPrefix(int $userId, string $prefix, Permission $needed = Permission::Read): array
    {
        if ('' === $prefix || !$this->iamConfig->isSharingEnabled($userId)) {
            return [];
        }

        $groupIds = array_map(
            static fn ($member): int => $member->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );

        $ids = [];
        foreach ($this->shareRepository->findForSubjects($userId, $groupIds) as $share) {
            if (!str_starts_with($share->getResourceKind(), $prefix)) {
                continue;
            }
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission || !$permission->implies($needed)) {
                continue;
            }
            if (!ctype_digit($share->getResourceId())) {
                continue;
            }
            $ids[] = (int) $share->getResourceId();
        }

        return array_values(array_unique($ids));
    }
}
