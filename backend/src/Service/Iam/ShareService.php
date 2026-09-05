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
use App\Service\Iam\ResourceKind\ResourceCard;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;

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

        if (null === $kindImpl->ownerId($resourceId)) {
            throw new \InvalidArgumentException('This item was not found.');
        }

        if (!in_array($level, $kindImpl->supportedPermissions(), true)) {
            throw new ShareNotAllowedException(sprintf('This item cannot be shared with "%s".', $level->value));
        }

        if (Share::SUBJECT_EVERYONE === $subjectType) {
            $subjectId = 0;
            if (!$this->iamConfig->canShareWithEveryone($actor)) {
                throw new ShareNotAllowedException('Only an administrator can share with everyone on this instance.');
            }
        }

        $this->assertSubjectExists($subjectType, $subjectId);

        if (!$this->accessGate->decide($actor, $kind, $resourceId, Permission::Manage)) {
            throw new ShareNotAllowedException('Only the owner or someone who can manage this item may share it.');
        }

        $share = $this->shareRepository->findOneForSubject($kind, $resourceId, $subjectType, $subjectId);
        if (null === $share) {
            $share = new Share();
            $share->setResourceKind($kind);
            $share->setResourceId($resourceId);
            $share->setSubjectType($subjectType);
            $share->setSubjectId($subjectId);
        }
        $share->setPermission($level->value);
        $share->setGrantedBy((int) $actor->getId());
        $this->shareRepository->save($share);

        $this->auditLogWriter->record(
            (int) $actor->getId(),
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
     * @return list<array{card: ResourceCard, permission: string, ownerId: int|null}>
     */
    public function listSharedWith(int $userId, string $kind): array
    {
        $groupIds = array_map(
            static fn ($m): int => $m->getGroupId(),
            $this->groupMemberRepository->findByUserId($userId),
        );
        $byResource = [];
        foreach ($this->shareRepository->findForSubjects($userId, $groupIds, $kind) as $share) {
            $id = $share->getResourceId();
            $permission = Permission::tryFrom($share->getPermission());
            if (null === $permission) {
                continue;
            }
            $existing = $byResource[$id] ?? null;
            if (null === $existing || $permission->implies($existing)) {
                $byResource[$id] = $permission;
            }
        }

        $kindImpl = $this->registry->get($kind);
        $out = [];
        foreach ($byResource as $resourceId => $permission) {
            $out[] = [
                'card' => $kindImpl->describe((string) $resourceId),
                'permission' => $permission->value,
                'ownerId' => $kindImpl->ownerId((string) $resourceId),
            ];
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchSubjects(string $query, int $limit = 20): array
    {
        $query = trim($query);
        $out = [[
            'type' => Share::SUBJECT_EVERYONE,
            'id' => 0,
            'name' => '',
            'email' => null,
            'pinned' => true,
        ]];

        if ('' !== $query) {
            foreach ($this->userRepository->searchByEmailOrName($query, $limit) as $user) {
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
            foreach ($this->groupRepository->findAllOrderedByName() as $group) {
                $out[] = [
                    'type' => Share::SUBJECT_GROUP,
                    'id' => (int) $group->getId(),
                    'name' => $group->getName(),
                    'email' => null,
                    'pinned' => false,
                ];
                if (count($out) >= $limit + 1) {
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
        $name = '';
        $email = null;
        if (Share::SUBJECT_USER === $share->getSubjectType()) {
            $user = $this->userRepository->find($share->getSubjectId());
            if ($user instanceof User) {
                $name = $this->displayName($user);
                $email = $user->getMail();
            }
        } elseif (Share::SUBJECT_GROUP === $share->getSubjectType()) {
            $group = $this->groupRepository->find($share->getSubjectId());
            if ($group instanceof Group) {
                $name = $group->getName();
            }
        }

        return [
            'id' => $share->getId(),
            'kind' => $share->getResourceKind(),
            'resourceId' => $share->getResourceId(),
            'subjectType' => $share->getSubjectType(),
            'subjectId' => $share->getSubjectId(),
            'permission' => $share->getPermission(),
            'name' => $name,
            'email' => $email,
            'grantedBy' => $share->getGrantedBy(),
            'created' => $share->getCreated(),
        ];
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
                throw new \InvalidArgumentException(sprintf('User %d was not found.', $subjectId));
            }

            return;
        }
        $group = $this->groupRepository->find($subjectId);
        if (!$group instanceof Group) {
            throw new \InvalidArgumentException(sprintf('Group %d was not found.', $subjectId));
        }
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
