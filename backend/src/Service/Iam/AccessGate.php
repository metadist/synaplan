<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\User;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Single decision point for IAM permissions.
 *
 * S1 body is owner-only: {@see ResourceKindRegistry} → ownerId === userId.
 * When groups are disabled the method returns before any IAM table is touched
 * (there is no share/membership lookup yet; this early return is the S2 seam).
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
    ) {
    }

    public function decide(User $user, string $kind, string $resourceId, Permission $level): bool
    {
        unset($level); // S1: owner has every level; unused until shares exist.

        $userId = (int) $user->getId();

        // Flag-off path: owner check only, no IAM table I/O.
        if (!$this->iamConfig->isGroupsEnabled($userId)) {
            return $this->isOwner($kind, $resourceId, $userId);
        }

        // S1: sharing is not wired yet — still owner-only. S2 adds BSHARES here.
        return $this->isOwner($kind, $resourceId, $userId);
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
