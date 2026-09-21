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
use App\Service\Iam\ResourceKind\ConversationKind;
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

        // An administrator manages shares on the owner's behalf without holding
        // a grant of their own. That power must not become a way to read the
        // content: a subject that includes the actor (themselves, a group they
        // belong to, everyone) is refused unless the actor already holds access.
        if (null === $this->accessGate->highestGranted($actor, $kind, $resourceId)
            && $this->subjectReaches($actor, $subjectType, $subjectId)
        ) {
            throw new ShareNotAllowedException('Administrators can share on behalf of the owner, but not with themselves.');
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
        $kindImpl->assertShareable($resourceId);
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
        $this->registry->get($kind)->onShareChanged($resourceId, [
            'subjectType' => $subjectType,
            'subjectId' => $subjectId,
        ]);
    }

    /**
     * Metadata for everything shared with one group. Content is never included.
     *
     * @return list<array<string, mixed>>
     */
    public function describeGrantsToGroup(int $groupId): array
    {
        $out = [];
        foreach ($this->shareRepository->findBySubject(Share::SUBJECT_GROUP, $groupId) as $share) {
            try {
                $kind = $this->registry->get($share->getResourceKind());
            } catch (UnknownResourceKindException) {
                $out[] = [
                    'kind' => $share->getResourceKind(),
                    'id' => $share->getResourceId(),
                    'name' => $share->getResourceId(),
                    'icon' => 'file',
                    'meta' => [],
                    'permission' => $share->getPermission(),
                    'ownerId' => null,
                    'ownerName' => null,
                ];
                continue;
            }
            $card = $kind->describe($share->getResourceId());
            $row = $this->serializeSharedCard(
                $card,
                $share->getPermission(),
                $kind->ownerId($share->getResourceId()),
            );
            $row['kind'] = $share->getResourceKind();
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Who an owned conversation is shared with, for the history row.
     * Public links are a separate flag on the chat and are not included.
     *
     * @param list<int> $chatIds
     *
     * @return array<string, array{everyone: bool, people: int, groups: list<string>}>
     */
    public function summarizeConversations(array $chatIds): array
    {
        $ids = array_map(static fn (int $id): string => (string) $id, $chatIds);
        $out = [];
        foreach ($ids as $id) {
            $out[$id] = ['everyone' => false, 'people' => 0, 'groups' => []];
        }
        if ([] === $ids) {
            return $out;
        }

        $groupIds = [];
        $shares = $this->shareRepository->findForResources(ConversationKind::KEY, $ids);
        foreach ($shares as $share) {
            if (Share::SUBJECT_GROUP === $share->getSubjectType()) {
                $groupIds[] = $share->getSubjectId();
            }
        }
        $groupNames = [];
        foreach ($this->groupRepository->findByIds(array_values(array_unique($groupIds))) as $group) {
            $groupNames[(int) $group->getId()] = $group->getName();
        }

        foreach ($shares as $share) {
            $id = $share->getResourceId();
            if (!isset($out[$id])) {
                $out[$id] = ['everyone' => false, 'people' => 0, 'groups' => []];
            }
            if (Share::SUBJECT_EVERYONE === $share->getSubjectType()) {
                $out[$id]['everyone'] = true;
            } elseif (Share::SUBJECT_USER === $share->getSubjectType()) {
                ++$out[$id]['people'];
            } elseif (Share::SUBJECT_GROUP === $share->getSubjectType()) {
                $name = $groupNames[$share->getSubjectId()] ?? null;
                if (null !== $name && '' !== $name) {
                    $out[$id]['groups'][] = $name;
                }
            }
        }

        return $out;
    }

    /**
     * How many shares this user granted to one group.
     */
    public function countOwnGrantsToGroup(User $actor, int $groupId): int
    {
        return $this->shareRepository->countGrantedByUserToGroup((int) $actor->getId(), $groupId);
    }

    /**
     * Deletes only the shares this user granted to one group.
     *
     * A plain leave must not call this. Remaining members keep what was shared
     * with them unless the leaving person explicitly chooses to stop sharing.
     *
     * @return int number of share rows removed
     */
    public function withdrawOwnGrantsToGroup(User $actor, int $groupId, string $ip = ''): int
    {
        $shares = $this->shareRepository->findGrantedByUserToGroup((int) $actor->getId(), $groupId);
        foreach ($shares as $share) {
            $kind = $share->getResourceKind();
            $resourceId = $share->getResourceId();
            $this->shareRepository->remove($share);
            $this->auditLogWriter->record(
                (int) $actor->getId(),
                'share.revoke',
                $kind,
                $resourceId,
                [
                    'subjectType' => Share::SUBJECT_GROUP,
                    'subjectId' => $groupId,
                    'reason' => 'leave_and_stop_sharing',
                ],
                $ip,
            );
            try {
                $this->registry->get($kind)->onShareChanged($resourceId, [
                    'subjectType' => Share::SUBJECT_GROUP,
                    'subjectId' => $groupId,
                ]);
            } catch (UnknownResourceKindException) {
                // The row is already gone. An uninstalled kind has nothing left to clean up.
            }
        }

        return \count($shares);
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
     * User accounts are searchable only when
     * {@see IamConfig::isUserSearchEnabled()} is on — otherwise the picker
     * would expose every registered account on a public instance (#2060).
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
        $usersVisible = $this->iamConfig->isUserSearchEnabled($actorId);

        if ('' !== $query) {
            if ($usersVisible) {
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

    /**
     * Would a grant to this subject give the actor access?
     */
    private function subjectReaches(User $actor, string $subjectType, int $subjectId): bool
    {
        $actorId = (int) $actor->getId();

        return match ($subjectType) {
            Share::SUBJECT_EVERYONE => true,
            Share::SUBJECT_USER => $subjectId === $actorId,
            Share::SUBJECT_GROUP => null !== $this->groupMemberRepository->findMembership($subjectId, $actorId),
            default => false,
        };
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
        return $user->getDisplayName();
    }
}
