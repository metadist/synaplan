<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Entity\Group;
use App\Entity\GroupMember;
use App\Entity\User;
use App\Repository\GroupMemberRepository;
use App\Repository\GroupRepository;
use App\Service\Auth\OidcClaimResolver;

/**
 * Upserts directory groups from an OIDC claim and reconciles this user's
 * directory memberships. Manual memberships are never touched.
 */
final readonly class DirectoryGroupSync
{
    public const BEARER_THROTTLE_SECONDS = 300;

    public function __construct(
        private IamConfig $iamConfig,
        private OidcClaimResolver $claimResolver,
        private GroupRepository $groupRepository,
        private GroupMemberRepository $groupMemberRepository,
        private AuditLogWriter $auditLogWriter,
        private string $oidcClientId = '',
        private string $oidcDiscoveryUrl = '',
    ) {
    }

    /**
     * Browser login (a refresh token is present) always syncs. The bearer
     * path runs on every request, so it is skipped when lastSeen is younger
     * than {@see self::BEARER_THROTTLE_SECONDS}.
     */
    public function shouldRun(User $user, ?string $refreshToken, int $lastSeenAt): bool
    {
        if (!$this->iamConfig->isDirectorySyncEnabled((int) $user->getId())) {
            return false;
        }
        if (null !== $refreshToken && '' !== $refreshToken) {
            return true;
        }

        return $lastSeenAt <= time() - self::BEARER_THROTTLE_SECONDS;
    }

    /**
     * @param array<string, mixed> $claims
     */
    public function sync(User $user, array $claims, string $ip = ''): void
    {
        $userId = (int) $user->getId();
        if (!$this->iamConfig->isDirectorySyncEnabled($userId)) {
            return;
        }

        $issuer = $this->claimResolver->issuer($claims, $this->oidcDiscoveryUrl);
        $source = 'oidc:'.$issuer;
        $paths = $this->claimResolver->paths($this->iamConfig->directoryGroupsClaim($userId), $this->oidcClientId);
        $externalIds = $this->claimResolver->values($claims, $paths);
        $names = $this->iamConfig->directoryGroupNames($userId);

        $desiredGroupIds = [];
        foreach ($externalIds as $externalId) {
            $group = $this->upsertDirectoryGroup($source, $externalId, $names[$externalId] ?? $externalId);
            $desiredGroupIds[(int) $group->getId()] = $group;
        }

        $existing = $this->groupMemberRepository->findDirectoryByUserId($userId);
        foreach ($existing as $member) {
            $groupId = $member->getGroupId();
            if (isset($desiredGroupIds[$groupId])) {
                unset($desiredGroupIds[$groupId]);
                continue;
            }
            $this->groupMemberRepository->remove($member);
            $this->auditLogWriter->record(
                $userId,
                'directory.sync',
                'group',
                (string) $groupId,
                ['change' => 'remove', 'targetUserId' => $userId],
                $ip,
            );
        }

        foreach ($desiredGroupIds as $groupId => $group) {
            if (null !== $this->groupMemberRepository->findMembership($groupId, $userId)) {
                continue;
            }
            $member = new GroupMember($groupId, $userId);
            $member->setSource(GroupMember::SOURCE_DIRECTORY);
            $member->setRole(GroupMember::ROLE_MEMBER);
            $this->groupMemberRepository->save($member);
            $this->auditLogWriter->record(
                $userId,
                'directory.sync',
                'group',
                (string) $groupId,
                ['change' => 'add', 'targetUserId' => $userId, 'name' => $group->getName()],
                $ip,
            );
        }
    }

    private function upsertDirectoryGroup(string $source, string $externalId, string $displayName): Group
    {
        $existing = $this->groupRepository->findOneByExternal($source, $externalId);
        if ($existing instanceof Group) {
            if ($existing->getName() !== $displayName && '' !== trim($displayName)) {
                $existing->setName($displayName);
                $this->groupRepository->save($existing);
            }

            return $existing;
        }

        $group = new Group();
        $group->setName($displayName);
        $group->setSlug($this->uniqueDirectorySlug($displayName));
        $group->setKind(Group::KIND_DIRECTORY);
        $group->setExternalSource($source);
        $group->setExternalId($externalId);
        $this->groupRepository->save($group);

        return $group;
    }

    private function uniqueDirectorySlug(string $name): string
    {
        $base = 'dir-'.$this->slugify($name);
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
        if (strlen($slug) > 110) {
            $slug = rtrim(substr($slug, 0, 110), '-');
        }

        return $slug;
    }
}
