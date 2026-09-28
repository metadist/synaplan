<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Repository\UserRepository;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;

/**
 * Names for an audit page. The log stays metadata: a missing name stays empty
 * rather than falling back to a raw id.
 */
final readonly class AuditRowLabels
{
    public function __construct(
        private UserRepository $userRepository,
        private GroupRepository $groupRepository,
        private ResourceKindRegistry $kinds,
    ) {
    }

    /**
     * @param list<AuditLogEntry> $rows
     *
     * @return list<array{actorName: ?string, resourceName: ?string, subjectName: ?string}>
     */
    public function forRows(array $rows): array
    {
        $userIds = [];
        $groupIds = [];
        foreach ($rows as $row) {
            if ($row->getActorId() > 0) {
                $userIds[] = $row->getActorId();
            }
            $subject = $row->getSubject() ?? [];
            $subjectUser = $this->subjectUserId($subject);
            if (null !== $subjectUser) {
                $userIds[] = $subjectUser;
            }
            $subjectGroup = $this->subjectGroupId($subject);
            if (null !== $subjectGroup) {
                $groupIds[] = $subjectGroup;
            }
            if ('group' === $row->getResourceKind() && ctype_digit($row->getResourceId())) {
                $groupIds[] = (int) $row->getResourceId();
            }
        }

        $users = $this->usersById($userIds);
        $groups = $this->groupsById($groupIds);

        $out = [];
        foreach ($rows as $row) {
            $subject = $row->getSubject() ?? [];
            $out[] = [
                'actorName' => $users[$row->getActorId()] ?? null,
                'resourceName' => $this->resourceName($row, $subject, $groups),
                'subjectName' => $this->subjectName($subject, $users, $groups),
            ];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<int, string>   $groups
     */
    private function resourceName(AuditLogEntry $row, array $subject, array $groups): ?string
    {
        $kind = $row->getResourceKind();
        $id = $row->getResourceId();
        if ('group' === $kind && ctype_digit($id) && isset($groups[(int) $id])) {
            return $groups[(int) $id];
        }
        if ('group' === $kind && isset($subject['name']) && is_string($subject['name']) && '' !== trim($subject['name'])) {
            return trim($subject['name']);
        }
        if ('' === $kind || '' === $id) {
            return null;
        }

        try {
            $card = $this->kinds->get($kind)->describe($id);
        } catch (UnknownResourceKindException) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        $name = trim($card->name);
        if ('' === $name || $name === $id || str_starts_with($name, '#')) {
            return null;
        }

        return $name;
    }

    /**
     * @param array<string, mixed> $subject
     * @param array<int, string>   $users
     * @param array<int, string>   $groups
     */
    private function subjectName(array $subject, array $users, array $groups): ?string
    {
        $userId = $this->subjectUserId($subject);
        if (null !== $userId && isset($users[$userId])) {
            return $users[$userId];
        }
        $groupId = $this->subjectGroupId($subject);
        if (null !== $groupId && isset($groups[$groupId])) {
            return $groups[$groupId];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function subjectUserId(array $subject): ?int
    {
        if (($subject['subjectType'] ?? null) === 'user' && is_numeric($subject['subjectId'] ?? null)) {
            return (int) $subject['subjectId'];
        }
        if (is_numeric($subject['userId'] ?? null)) {
            return (int) $subject['userId'];
        }

        return null;
    }

    /**
     * @param array<string, mixed> $subject
     */
    private function subjectGroupId(array $subject): ?int
    {
        if (($subject['subjectType'] ?? null) === 'group' && is_numeric($subject['subjectId'] ?? null)) {
            return (int) $subject['subjectId'];
        }

        return null;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function usersById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return [];
        }
        $out = [];
        foreach ($this->userRepository->findBy(['id' => $ids]) as $user) {
            if ($user instanceof User && null !== $user->getId()) {
                $out[(int) $user->getId()] = $user->getDisplayName();
            }
        }

        return $out;
    }

    /**
     * @param list<int> $ids
     *
     * @return array<int, string>
     */
    private function groupsById(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        if ([] === $ids) {
            return [];
        }
        $out = [];
        foreach ($this->groupRepository->findByIds($ids) as $group) {
            if (null !== $group->getId()) {
                $out[(int) $group->getId()] = $group->getName();
            }
        }

        return $out;
    }
}
