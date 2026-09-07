<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Group;
use App\Entity\Share;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use App\Service\Iam\Exception\ShareNotAllowedException;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\ResourceKind\AgentKind;
use App\Service\Iam\ResourceKind\ResourceCard;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use App\Service\Iam\ResourceKind\ShareableResourceKindInterface;

/**
 * Grant, revoke and list shares. Sharing stays off until both IAM flags are on.
 */
final readonly class ShareService
{
    public function __construct(
        private ShareRepository $shareRepository,
        private GroupRepository $groupRepository,
        private GroupMemberRepository $groupMemberRepository,
        private UserRepository $userRepository,
        private ResourceKindRegistry $registry,
        private AccessGate $accessGate,
        private IamConfig $iamConfig,
        private AuditLogWriter $auditLogWriter,
    ) {
    }

    public function grant(
        User $actor,
        string $kind,
        string $resourceId,
        string $subjectType,
        int $subjectId,
        string $permission,
        string $ip = '',
    ): Share {
        $this->assertSharingOn($actor);
        if (!in_array($subjectType, Share::SUBJECT_TYPES, true)) {
            throw new \InvalidArgumentException('Subject must be a person, a group, or everyone.');
        }
        $level = Permission::tryFrom($permission);
        if (null === $level) {
            throw new \InvalidArgumentException('Permission must be read, use, edit or manage.');
        }

        try {
            $kindImpl = $this->registry->get($kind);
        } catch (UnknownResourceKindException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $ownerId = $kindImpl->ownerId($resourceId);
        if (null === $ownerId) {
            throw new \InvalidArgumentException('This item was not found.');
        }
        if (0 === $ownerId) {
            throw new ShareNotAllowedException('This item cannot be shared.');
        }
        $this->assertKindAllows($kindImpl, $resourceId, $level);

        if (Share::SUBJECT_EVERYONE === $subjectType) {
            $subjectId = 0;
            if (!$this->iamConfig->canShareWithEveryone($actor)) {
                throw new ShareNotAllowedException('Only an administrator can share with everyone on this instance.');
            }
        }

        if (Share::SUBJECT_USER === $subjectType && $subjectId === (int) $actor->getId()) {
            throw new \InvalidArgumentException('You already have full access to your own item.');
        }
        $this->assertSubjectExists($subjectType, $subjectId);

        if (!$this->accessGate->decide($actor, $kind, $resourceId, Permission::Manage)) {
            throw new ShareNotAllowedException('Only the owner or someone who can manage this item may share it.');
        }

        return $this->writeGrant((int) $actor->getId(), $kindImpl, $resourceId, $subjectType, $subjectId, $level, $ip);
    }

    /**
     * Grant on a system-owned resource (owner 0) on behalf of the platform —
     * used by seeders and commands, never by a request. Goes through the same
     * kind checks and audit trail as {@see self::grant()}; the actor is 0.
     */
    public function grantAsSystem(string $kind, string $resourceId, string $subjectType, int $subjectId, Permission $level): Share
    {
        if (!in_array($subjectType, Share::SUBJECT_TYPES, true)) {
            throw new \InvalidArgumentException('Subject must be a person, a group, or everyone.');
        }
        try {
            $kindImpl = $this->registry->get($kind);
        } catch (UnknownResourceKindException $e) {
            throw new \InvalidArgumentException($e->getMessage(), 0, $e);
        }
        if (0 !== $kindImpl->ownerId($resourceId)) {
            throw new ShareNotAllowedException('Only system-owned items can be shared by the platform.');
        }
        $this->assertKindAllows($kindImpl, $resourceId, $level);
        if (Share::SUBJECT_EVERYONE === $subjectType) {
            $subjectId = 0;
        } else {
            $this->assertSubjectExists($subjectType, $subjectId);
        }

        return $this->writeGrant(0, $kindImpl, $resourceId, $subjectType, $subjectId, $level, '');
    }

    private function assertKindAllows(ShareableResourceKindInterface $kindImpl, string $resourceId, Permission $level): void
    {
        if ($kindImpl instanceof AgentKind) {
            $kindImpl->assertShareable($resourceId);
        }
        if (!in_array($level, $kindImpl->supportedPermissions(), true)) {
            throw new ShareNotAllowedException(sprintf('This item cannot be shared with "%s".', $level->value));
        }
    }

    private function writeGrant(
        int $actorId,
        ShareableResourceKindInterface $kindImpl,
        string $resourceId,
        string $subjectType,
        int $subjectId,
        Permission $level,
        string $ip,
    ): Share {
        $kind = $kindImpl->key();
        $share = $this->shareRepository->findOneForSubject($kind, $resourceId, $subjectType, $subjectId);
        if (null === $share) {
            $share = new Share();
            $share->setResourceKind($kind);
            $share->setResourceId($resourceId);
            $share->setSubjectType($subjectType);
            $share->setSubjectId($subjectId);
        }
        $share->setPermission($level->value);
        $share->setGrantedBy($actorId);
        $this->shareRepository->save($share);

        $this->auditLogWriter->record(
            $actorId,
            'share.grant',
            $kind,
            $resourceId,
            ['subjectType' => $subjectType, 'subjectId' => $subjectId, 'permission' => $level->value],
            $ip,
        );
        $kindImpl->onShareChanged($resourceId);

        return $share;
    }

    public function revoke(
        User $actor,
        string $kind,
        string $resourceId,
        string $subjectType,
        int $subjectId,
        string $ip = '',
    ): void {
        $this->assertSharingOn($actor);
        if (Share::SUBJECT_EVERYONE === $subjectType) {
            $subjectId = 0;
        }

        if (!$this->accessGate->decide($actor, $kind, $resourceId, Permission::Manage)) {
            throw new ShareNotAllowedException('Only the owner or someone who can manage this item may change sharing.');
        }

        $share = $this->shareRepository->findOneForSubject($kind, $resourceId, $subjectType, $subjectId);
        if (null === $share) {
            return;
        }

        $this->shareRepository->remove($share);
        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'share.revoke',
            $kind,
            $resourceId,
            ['subjectType' => $subjectType, 'subjectId' => $subjectId],
            $ip,
        );
        $this->registry->get($kind)->onShareChanged($resourceId);
    }

    /**
     * @return list<Share>
     */
    public function listForResource(string $kind, string $resourceId): array
    {
        return $this->shareRepository->findForResource($kind, $resourceId);
    }

    /**
     * Resources shared with this user, one row per resource. `share` is the
     * grant that wins (highest permission), `sharedAt` the moment the resource
     * first reached the user through any of their subjects.
     *
     * @return list<array{card: ResourceCard, permission: string, ownerId: int|null, share: Share, sharedAt: int}>
     */
    public function listSharedWith(int $userId, string $kind): array
    {
        $groupIds = array_map(
            static fn ($m): int => $m->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );
        $byResource = $this->winnersByResource(
            $this->shareRepository->findForSubjects($userId, $groupIds, $kind)
        );

        $kindImpl = $this->registry->get($kind);
        $out = [];
        foreach ($byResource as $resourceId => $winner) {
            $out[] = [
                'card' => $kindImpl->describe((string) $resourceId),
                'permission' => $winner['permission']->value,
                'ownerId' => $kindImpl->ownerId((string) $resourceId),
                'share' => $winner['share'],
                'sharedAt' => $winner['sharedAt'],
            ];
        }

        return $out;
    }

    /**
     * How one resource reached this user (winning grant), or null if it did not.
     *
     * @return array{type: string, name: string}|null
     */
    public function sharedViaFor(int $userId, string $kind, string $resourceId): ?array
    {
        $row = $this->winningShareFor($userId, $kind, $resourceId);
        if (null === $row) {
            return null;
        }

        return [
            'type' => $row['share']->getSubjectType(),
            'name' => $this->sharedViaName($row['share']),
        ];
    }

    /**
     * @return array{card: ResourceCard, permission: string, ownerId: int|null, share: Share, sharedAt: int}|null
     */
    public function winningShareFor(int $userId, string $kind, string $resourceId): ?array
    {
        $groupIds = array_map(
            static fn ($m): int => $m->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );
        $winners = $this->winnersByResource(
            $this->shareRepository->findForSubjects($userId, $groupIds, $kind, $resourceId)
        );
        $winner = $winners[$resourceId] ?? null;
        if (null === $winner) {
            return null;
        }

        $kindImpl = $this->registry->get($kind);

        return [
            'card' => $kindImpl->describe($resourceId),
            'permission' => $winner['permission']->value,
            'ownerId' => $kindImpl->ownerId($resourceId),
            'share' => $winner['share'],
            'sharedAt' => $winner['sharedAt'],
        ];
    }

    /**
     * People and groups the actor may share with. "Everyone" is pinned first
     * only when {@see IamConfig::canShareWithEveryone()} allows this actor.
     *
     * @return list<array<string, mixed>>
     */
    public function searchSubjects(User $actor, string $query, int $limit = 20): array
    {
        $query = trim($query);
        $out = [];
        if ($this->iamConfig->canShareWithEveryone($actor)) {
            $out[] = [
                'type' => Share::SUBJECT_EVERYONE,
                'id' => 0,
                'name' => '',
                'email' => null,
                'pinned' => true,
            ];
        }
        $actorId = (int) $actor->getId();

        if ('' !== $query) {
            foreach ($this->userRepository->searchByEmailOrName($query, $limit) as $user) {
                if ((int) $user->getId() === $actorId) {
                    continue;
                }
                $out[] = [
                    'type' => Share::SUBJECT_USER,
                    'id' => (int) $user->getId(),
                    'name' => $this->displayName($user),
                    'email' => $user->getMail(),
                    'pinned' => false,
                ];
            }
            foreach ($this->groupRepository->searchByName($query, $limit) as $group) {
                $out[] = [
                    'type' => Share::SUBJECT_GROUP,
                    'id' => (int) $group->getId(),
                    'name' => $group->getName(),
                    'email' => null,
                    'pinned' => false,
                ];
            }
        } else {
            $listed = 0;
            foreach ($this->groupRepository->findAllOrderedByName() as $group) {
                $out[] = [
                    'type' => Share::SUBJECT_GROUP,
                    'id' => (int) $group->getId(),
                    'name' => $group->getName(),
                    'email' => null,
                    'pinned' => false,
                ];
                if (++$listed >= $limit) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeShare(Share $share): array
    {
        $subject = $this->subjectNameAndEmail($share);

        return [
            'id' => $share->getId(),
            'kind' => $share->getResourceKind(),
            'resourceId' => $share->getResourceId(),
            'subjectType' => $share->getSubjectType(),
            'subjectId' => $share->getSubjectId(),
            'permission' => $share->getPermission(),
            'name' => $subject['name'],
            'email' => $subject['email'],
            'grantedBy' => $share->getGrantedBy(),
            'created' => $share->getCreated(),
        ];
    }

    /**
     * One "shared with me" list entry: the resource card plus how and when it
     * reached the user, and whether it arrived after they last looked.
     *
     * @param array{card: ResourceCard, permission: string, ownerId: int|null, share: Share, sharedAt: int} $row
     *
     * @return array<string, mixed>
     */
    public function serializeSharedItem(array $row, int $viewerId, int $lastSeenAt): array
    {
        $share = $row['share'];
        $item = $this->serializeSharedCard($row['card'], $row['permission'], $row['ownerId']);
        $item['sharedVia'] = [
            'type' => $share->getSubjectType(),
            'name' => $this->sharedViaName($share),
        ];
        $item['sharedAt'] = $row['sharedAt'];
        $item['isNew'] = $share->getGrantedBy() !== $viewerId && $row['sharedAt'] > $lastSeenAt;

        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeSharedCard(ResourceCard $card, string $permission, ?int $ownerId): array
    {
        $ownerName = null;
        if (null !== $ownerId) {
            $owner = $this->userRepository->find($ownerId);
            if ($owner instanceof User) {
                $ownerName = $this->displayName($owner);
            }
        }

        return [
            'id' => $card->id,
            'name' => $card->name,
            'icon' => $card->icon,
            'meta' => $card->meta,
            'permission' => $permission,
            'ownerId' => $ownerId,
            'ownerName' => $ownerName,
        ];
    }

    private function assertSharingOn(User $actor): void
    {
        if (!$this->iamConfig->isSharingEnabled((int) $actor->getId())) {
            throw new ShareNotAllowedException('Sharing is not enabled.');
        }
    }

    private function assertSubjectExists(string $subjectType, int $subjectId): void
    {
        if (Share::SUBJECT_EVERYONE === $subjectType) {
            return;
        }
        if (Share::SUBJECT_USER === $subjectType) {
            $user = $this->userRepository->find($subjectId);
            if (!$user instanceof User) {
                throw new \InvalidArgumentException('That person was not found.');
            }

            return;
        }
        $group = $this->groupRepository->find($subjectId);
        if (!$group instanceof Group) {
            throw new \InvalidArgumentException('That group was not found.');
        }
    }

    /**
     * How the share reached the viewer. Only a group name is useful here —
     * a user subject is the viewer themselves, and everyone has no name.
     */
    private function sharedViaName(Share $share): string
    {
        if (Share::SUBJECT_GROUP !== $share->getSubjectType()) {
            return '';
        }

        return $this->subjectNameAndEmail($share)['name'];
    }

    /**
     * @param list<Share> $shares
     *
     * @return array<string, array{permission: Permission, share: Share, sharedAt: int}>
     */
    private function winnersByResource(array $shares): array
    {
        /** @var array<string, array{permission: Permission, share: Share, sharedAt: int}> $byResource */
        $byResource = [];
        foreach ($shares as $share) {
            $id = $share->getResourceId();
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission) {
                continue;
            }
            $existing = $byResource[$id] ?? null;
            if (null === $existing) {
                $byResource[$id] = ['permission' => $permission, 'share' => $share, 'sharedAt' => $share->getCreated()];
                continue;
            }
            $byResource[$id]['sharedAt'] = min($existing['sharedAt'], $share->getCreated());
            if ($this->outranks($share, $permission, $existing['share'], $existing['permission'])) {
                $byResource[$id]['permission'] = $permission;
                $byResource[$id]['share'] = $share;
            }
        }

        return $byResource;
    }

    /**
     * A higher permission always wins. On equal permission the more specific
     * subject wins (person over group over everyone), so the list can say
     * "Group · Sales" instead of "Everyone" when both grants exist.
     */
    private function outranks(Share $candidate, Permission $candidateLevel, Share $current, Permission $currentLevel): bool
    {
        if ($candidateLevel === $currentLevel) {
            return self::subjectSpecificity($candidate) > self::subjectSpecificity($current);
        }

        return $candidateLevel->implies($currentLevel);
    }

    private static function subjectSpecificity(Share $share): int
    {
        return match ($share->getSubjectType()) {
            Share::SUBJECT_USER => 3,
            Share::SUBJECT_GROUP => 2,
            default => 1,
        };
    }

    /**
     * @return array{name: string, email: string|null}
     */
    private function subjectNameAndEmail(Share $share): array
    {
        if (Share::SUBJECT_USER === $share->getSubjectType()) {
            $user = $this->userRepository->find($share->getSubjectId());
            if ($user instanceof User) {
                return ['name' => $this->displayName($user), 'email' => $user->getMail()];
            }
        } elseif (Share::SUBJECT_GROUP === $share->getSubjectType()) {
            $group = $this->groupRepository->find($share->getSubjectId());
            if ($group instanceof Group) {
                return ['name' => $group->getName(), 'email' => null];
            }
        }

        return ['name' => '', 'email' => null];
    }

    private function displayName(User $user): string
    {
        $details = $user->getUserDetails();
        foreach (['full_name', 'first_name'] as $key) {
            $value = $details[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return (string) $user->getMail();
    }
}
