<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Service\Iam\Exception\DirectoryGroupReadOnlyException;

/**
 * Manual group CRUD + membership. Directory groups are read-only here (S4 writes them).
 */
final readonly class GroupService
{
    public function __construct(
        private GroupRepository $groupRepository,
        private GroupMemberRepository $groupMemberRepository,
        private UserRepository $userRepository,
        private AuditLogWriter $auditLogWriter,
    ) {
    }

    /**
     * @return list<Group>
     */
    public function listAll(): array
    {
        return $this->groupRepository->findAllOrderedByName();
    }

    public function get(int $groupId): ?Group
    {
        $group = $this->groupRepository->find($groupId);

        return $group instanceof Group ? $group : null;
    }

    public function create(string $name, string $description, User $actor, string $ip = ''): Group
    {
        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Group name is required.');
        }
        if (mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('Group name must be at most 128 characters.');
        }
        $description = trim($description);
        if (mb_strlen($description) > 512) {
            throw new \InvalidArgumentException('Group description must be at most 512 characters.');
        }

        $group = new Group();
        $group->setName($name);
        $group->setSlug($this->uniqueSlug($name));
        $group->setDescription($description);
        $group->setKind(Group::KIND_MANUAL);

        $this->groupRepository->save($group);

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'group.create',
            'group',
            (string) $group->getId(),
            ['name' => $group->getName(), 'slug' => $group->getSlug()],
            $ip,
        );

        return $group;
    }

    public function rename(Group $group, string $name, string $description, User $actor, string $ip = ''): Group
    {
        $this->assertManualGroup($group);

        $name = trim($name);
        if ('' === $name) {
            throw new \InvalidArgumentException('Group name is required.');
        }
        if (mb_strlen($name) > 128) {
            throw new \InvalidArgumentException('Group name must be at most 128 characters.');
        }
        $description = trim($description);
        if (mb_strlen($description) > 512) {
            throw new \InvalidArgumentException('Group description must be at most 512 characters.');
        }

        $previous = $group->getName();
        $group->setName($name);
        $group->setDescription($description);
        $this->groupRepository->save($group);

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'group.rename',
            'group',
            (string) $group->getId(),
            ['from' => $previous, 'to' => $group->getName()],
            $ip,
        );

        return $group;
    }

    public function delete(Group $group, User $actor, string $ip = ''): void
    {
        $this->assertManualGroup($group);

        $groupId = (int) $group->getId();
        $name = $group->getName();
        $this->groupMemberRepository->deleteByGroupId($groupId);
        $this->groupRepository->remove($group);

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'group.delete',
            'group',
            (string) $groupId,
            ['name' => $name],
            $ip,
        );
    }

    public function setMember(Group $group, int $userId, string $role, User $actor, string $ip = ''): GroupMember
    {
        $this->assertManualGroup($group);

        if (!in_array($role, GroupMember::ROLES, true)) {
            throw new \InvalidArgumentException('Role must be member or manager.');
        }

        $target = $this->userRepository->find($userId);
        if (!$target instanceof User) {
            throw new \InvalidArgumentException(sprintf('User %d was not found.', $userId));
        }

        $groupId = (int) $group->getId();
        $member = $this->groupMemberRepository->findMembership($groupId, $userId);
        if (null === $member) {
            $member = new GroupMember($groupId, $userId);
            $member->setSource(GroupMember::SOURCE_MANUAL);
        }
        $member->setRole($role);
        $this->groupMemberRepository->save($member);

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'group.member_set',
            'group',
            (string) $groupId,
            ['userId' => $userId, 'role' => $role],
            $ip,
        );

        return $member;
    }

    public function removeMember(Group $group, int $userId, User $actor, string $ip = ''): void
    {
        $this->assertManualGroup($group);

        $groupId = (int) $group->getId();
        $member = $this->groupMemberRepository->findMembership($groupId, $userId);
        if (null === $member) {
            return;
        }

        $this->groupMemberRepository->remove($member);

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'group.member_remove',
            'group',
            (string) $groupId,
            ['userId' => $userId],
            $ip,
        );
    }

    /**
     * @return list<array{group: Group, role: string}>
     */
    public function groupsOf(int $userId): array
    {
        $memberships = $this->groupMemberRepository->findByUserId($userId);
        if ([] === $memberships) {
            return [];
        }

        $groupIds = array_map(static fn (GroupMember $m): int => $m->getGroupId(), $memberships);
        $groups = [];
        foreach ($this->groupRepository->findByIds($groupIds) as $group) {
            $groups[(int) $group->getId()] = $group;
        }

        $out = [];
        foreach ($memberships as $membership) {
            $group = $groups[$membership->getGroupId()] ?? null;
            if (null === $group) {
                continue;
            }
            $out[] = ['group' => $group, 'role' => $membership->getRole()];
        }

        return $out;
    }

    /**
     * @return list<GroupMember>
     */
    public function membersOf(Group $group): array
    {
        return $this->groupMemberRepository->findByGroupId((int) $group->getId());
    }

    public function memberCount(Group $group): int
    {
        return $this->groupMemberRepository->countByGroupId((int) $group->getId());
    }

    /**
     * @param list<int> $groupIds
     *
     * @return array<int, int>
     */
    public function memberCounts(array $groupIds): array
    {
        return $this->groupMemberRepository->countByGroupIds($groupIds);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, list<array{id: int, name: string, role: string}>>
     */
    public function groupsByUserIds(array $userIds): array
    {
        $memberships = $this->groupMemberRepository->findByUserIds($userIds);
        if ([] === $memberships) {
            return [];
        }

        $groupIds = array_values(array_unique(array_map(
            static fn (GroupMember $m): int => $m->getGroupId(),
            $memberships,
        )));
        $groups = [];
        foreach ($this->groupRepository->findByIds($groupIds) as $group) {
            $groups[(int) $group->getId()] = $group;
        }

        $out = [];
        foreach ($memberships as $membership) {
            $group = $groups[$membership->getGroupId()] ?? null;
            if (null === $group) {
                continue;
            }
            $out[$membership->getUserId()][] = [
                'id' => (int) $group->getId(),
                'name' => $group->getName(),
                'role' => $membership->getRole(),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeGroup(Group $group, ?int $memberCount = null, ?string $role = null): array
    {
        $payload = [
            'id' => $group->getId(),
            'name' => $group->getName(),
            'slug' => $group->getSlug(),
            'description' => $group->getDescription(),
            'kind' => $group->getKind(),
            'memberCount' => $memberCount ?? $this->memberCount($group),
            'created' => $group->getCreated(),
            'updated' => $group->getUpdated(),
        ];
        if (null !== $role) {
            $payload['role'] = $role;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeMember(GroupMember $member, User $user): array
    {
        return [
            'userId' => $member->getUserId(),
            'email' => $user->getMail(),
            'role' => $member->getRole(),
            'source' => $member->getSource(),
            'created' => $member->getCreated(),
        ];
    }

    private function assertManualGroup(Group $group): void
    {
        if ($group->isDirectory()) {
            throw new DirectoryGroupReadOnlyException((int) $group->getId());
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = $this->slugify($name);
        $slug = $base;
        $n = 2;
        while (null !== $this->groupRepository->findOneBySlug($slug)) {
            $slug = $base.'-'.$n;
            ++$n;
        }

        return $slug;
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');
        if ('' === $slug) {
            $slug = 'group';
        }
        if (strlen($slug) > 120) {
            $slug = rtrim(substr($slug, 0, 120), '-');
        }

        return $slug;
    }
}
