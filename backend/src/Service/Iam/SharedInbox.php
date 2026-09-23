<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Repository\ConfigRepository;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;

/**
 * "New incoming" bookkeeping for items shared with a user.
 *
 * A single per-user, per-kind watermark (`IAM` / `SHARED_SEEN_AT_<kind>` in
 * BCONFIG) marks when the user last looked at their incoming list. Every
 * share that reached them after that moment counts as new. Shares the user
 * granted themselves never count.
 */
final readonly class SharedInbox
{
    public const SETTING_PREFIX = 'SHARED_SEEN_AT_';

    /** Resource ids opened from history since the last incoming-list visit. */
    public const OPENED_PREFIX = 'SHARED_OPENED_';

    public function __construct(
        private ConfigRepository $configRepository,
        private ShareService $shareService,
        private ResourceKindRegistry $registry,
    ) {
    }

    public function lastSeenAt(int $userId, string $kind): int
    {
        $value = $this->configRepository->getValue($userId, IamConfig::CONFIG_GROUP, self::settingFor($kind));

        return null === $value ? 0 : max(0, (int) $value);
    }

    /**
     * Moves the watermark to now (or the given moment) and returns it.
     *
     * @throws UnknownResourceKindException
     */
    public function markSeen(int $userId, string $kind, ?int $at = null): int
    {
        $this->registry->get($kind);
        $at ??= time();
        $this->configRepository->setValue($userId, IamConfig::CONFIG_GROUP, self::settingFor($kind), (string) $at);
        $this->configRepository->deleteValue($userId, IamConfig::CONFIG_GROUP, self::openedSettingFor($kind));

        return $at;
    }

    /**
     * Records one resource as opened. Does not move the incoming-list watermark,
     * so other unseen items of the same kind stay new.
     *
     * @throws UnknownResourceKindException
     * @throws \InvalidArgumentException    when the resource id is not a safe token
     */
    public function markItemSeen(int $userId, string $kind, string $resourceId): void
    {
        $this->registry->get($kind);
        if (1 !== preg_match('/^[A-Za-z0-9_-]{1,64}$/', $resourceId)) {
            throw new \InvalidArgumentException('resourceId is invalid.');
        }
        $ids = $this->openedIds($userId, $kind);
        $ids[$resourceId] = true;
        $ids = $this->stillNewOpenedIds($userId, $kind, $ids);
        $this->configRepository->setValue(
            $userId,
            IamConfig::CONFIG_GROUP,
            self::openedSettingFor($kind),
            implode(',', array_keys($ids)),
        );
    }

    /**
     * @return array<string, true>
     */
    public function openedIds(int $userId, string $kind): array
    {
        $value = $this->configRepository->getValue($userId, IamConfig::CONFIG_GROUP, self::openedSettingFor($kind));
        if (null === $value || '' === $value) {
            return [];
        }
        $ids = [];
        foreach (explode(',', $value) as $id) {
            if ('' !== $id) {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * Drop opened ids that can no longer count as new. Every id that is still
     * new is kept, so opening many chats cannot make an older opened chat new again.
     *
     * @param array<string, true> $opened
     *
     * @return array<string, true>
     */
    private function stillNewOpenedIds(int $userId, string $kind, array $opened): array
    {
        $lastSeenAt = $this->lastSeenAt($userId, $kind);
        $stillNew = [];
        foreach ($this->shareService->listSharedWith($userId, $kind) as $row) {
            if ($row['share']->getGrantedBy() === $userId) {
                continue;
            }
            if ($row['sharedAt'] <= $lastSeenAt) {
                continue;
            }
            $stillNew[$row['card']->id] = true;
        }
        $kept = [];
        foreach ($opened as $id => $_) {
            if (isset($stillNew[$id])) {
                $kept[$id] = true;
            }
        }

        return $kept;
    }

    /**
     * Number of resources of this kind that reached the user after they last
     * looked. Throws {@see UnknownResourceKindException} for an
     * unknown kind, like the list it is derived from.
     */
    public function countUnseen(int $userId, string $kind): int
    {
        $lastSeenAt = $this->lastSeenAt($userId, $kind);
        $opened = $this->openedIds($userId, $kind);
        $count = 0;
        foreach ($this->shareService->listSharedWith($userId, $kind) as $row) {
            if ($this->rowIsNew($row, $userId, $lastSeenAt, $opened)) {
                ++$count;
            }
        }

        return $count;
    }

    /**
     * @param array{card: ResourceKind\ResourceCard, share: \App\Entity\Share, sharedAt: int} $row
     * @param array<string, true>                                                             $opened
     */
    public function rowIsNew(array $row, int $userId, int $lastSeenAt, array $opened): bool
    {
        if ($row['share']->getGrantedBy() === $userId) {
            return false;
        }
        if ($row['sharedAt'] <= $lastSeenAt) {
            return false;
        }

        return !isset($opened[$row['card']->id]);
    }

    private static function settingFor(string $kind): string
    {
        return self::SETTING_PREFIX.$kind;
    }

    private static function openedSettingFor(string $kind): string
    {
        return self::OPENED_PREFIX.$kind;
    }
}
