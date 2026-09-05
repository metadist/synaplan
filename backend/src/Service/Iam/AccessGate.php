<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single decision point for IAM permissions.
 *
 * Owner always wins. When sharing is off, that is the whole decision and
 * BSHARES is never read. When sharing is on, the highest share that reaches
 * this user (themselves, any of their groups, or everyone) is compared with
 * {@see Permission::implies()}.
 *
 * Owner id is memoized per request, keyed (kind, resourceId).
 */
final readonly class AccessGate
{
    private const MEMO_ATTR = '_iam_access_gate_owners';

    public function __construct(
        private IamConfig $iamConfig,
        private ResourceKindRegistry $registry,
        private RequestStack $requestStack,
        private ShareRepository $shareRepository,
        private GroupMemberRepository $groupMemberRepository,
    ) {
    }

    public function decide(User $user, string $kind, string $resourceId, Permission $level): bool
    {
        $granted = $this->highestGranted($user, $kind, $resourceId);

        return null !== $granted && $granted->implies($level);
    }

    /**
     * Highest permission this user holds, or null if they have none.
     * The owner is treated as {@see Permission::Manage}.
     */
    public function highestGranted(User $user, string $kind, string $resourceId): ?Permission
    {
        $userId = (int) $user->getId();
        if ($this->isOwner($kind, $resourceId, $userId)) {
            return Permission::Manage;
        }

        if (!$this->iamConfig->isSharingEnabled($userId)) {
            return null;
        }

        $groupIds = array_map(
            static fn (GroupMember $member): int => $member->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );

        return $this->shareRepository->highestPermission($userId, $groupIds, $kind, $resourceId);
    }

    public function isOwnerOf(User $user, string $kind, string $resourceId): bool
    {
        return $this->isOwner($kind, $resourceId, (int) $user->getId());
    }

    private function isOwner(string $kind, string $resourceId, int $userId): bool
    {
        $ownerId = $this->memoizedOwnerId($kind, $resourceId);

        return null !== $ownerId && $ownerId === $userId;
    }

    private function memoizedOwnerId(string $kind, string $resourceId): ?int
    {
        $key = $kind."\0".$resourceId;
        $request = $this->requestStack->getCurrentRequest();
        if (null !== $request) {
            /** @var array<string, int|null> $memo */
            $memo = $request->attributes->get(self::MEMO_ATTR, []);
            if (array_key_exists($key, $memo)) {
                return $memo[$key];
            }
        }

        $ownerId = $this->resolveOwnerId($kind, $resourceId);

        if (null !== $request) {
            /** @var array<string, int|null> $memo */
            $memo = $request->attributes->get(self::MEMO_ATTR, []);
            $memo[$key] = $ownerId;
            $request->attributes->set(self::MEMO_ATTR, $memo);
        }

        return $ownerId;
    }

    private function resolveOwnerId(string $kind, string $resourceId): ?int
    {
        try {
            return $this->registry->get($kind)->ownerId($resourceId);
        } catch (UnknownResourceKindException) {
            return null;
        }
    }
}
