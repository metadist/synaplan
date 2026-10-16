<?php

declare(strict_types=1);

namespace App\Service\Iam;

use App\Repository\ConfigRepository;
use App\Service\Iam\Exception\UnknownResourceKindException;
use App\Service\Iam\ResourceKind\ResourceKindRegistry;
use Symfony\Component\Lock\LockFactory;

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

    private const OPEN_LOCK_TTL_SECONDS = 5.0;

    public function __construct(
        private ConfigRepository $configRepository,
        private ShareService $shareService,
        private ResourceKindRegistry $registry,
        private LockFactory $lockFactory,
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

        return $this->withOpenedLock($userId, $kind, function () use ($userId, $kind, $at): int {
            $this->configRepository->setValue($userId, IamConfig::CONFIG_GROUP, self::settingFor($kind), (string) $at);
            $this->configRepository->deleteValue($userId, IamConfig::CONFIG_GROUP, self::openedSettingFor($kind));

            return $at;
        });
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
        if (!self::isAcceptableResourceId($resourceId)) {
            throw new \InvalidArgumentException('resourceId is invalid.');
        }
        $this->withOpenedLock($userId, $kind, function () use ($userId, $kind, $resourceId): void {
            $current = $this->currentSharedAt($userId, $kind);
            if (!isset($current[$resourceId])) {
                return;
            }
            $marks = $this->openedMarks($userId, $kind);
            $marks[$resourceId] = $current[$resourceId];
            $this->writeOpenedMarks($userId, $kind, $this->pruneMarks($marks, $current));
        });
    }

    /**
     * Resource id => sharedAt recorded when that grant was opened.
     * A later share of the same resource has a different sharedAt and stays new.
     *
     * @return array<string, int>
     */
    public function openedMarks(int $userId, string $kind): array
    {
        $value = $this->configRepository->getValue($userId, IamConfig::CONFIG_GROUP, self::openedSettingFor($kind));
        if (null === $value || '' === $value) {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $marks = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['id'], $row['at'])) {
                continue;
            }
            if (!is_string($row['id']) && !is_int($row['id'])) {
                continue;
            }
            // Numeric ids are stored as JSON numbers when PHP casts the array
            // key to int. Accept both so a saved mark is still found on read.
            $marks[(string) $row['id']] = (int) $row['at'];
        }

        return $marks;
    }

    /**
     * @param array<string, int> $marks
     * @param array<string, int> $current resource id => sharedAt of grants that are still new
     *
     * @return array<string, int>
     */
    private function pruneMarks(array $marks, array $current): array
    {
        $kept = [];
        foreach ($marks as $id => $at) {
            if (isset($current[$id]) && $current[$id] === $at) {
                $kept[$id] = $at;
            }
        }

        return $kept;
    }

    /**
     * @return array<string, int>
     */
    private function currentSharedAt(int $userId, string $kind): array
    {
        $lastSeenAt = $this->lastSeenAt($userId, $kind);
        $current = [];
        foreach ($this->shareService->listSharedWith($userId, $kind) as $row) {
            if ($row['share']->getGrantedBy() === $userId) {
                continue;
            }
            if ($row['sharedAt'] <= $lastSeenAt) {
                continue;
            }
            $current[$row['card']->id] = $row['sharedAt'];
        }

        return $current;
    }

    /**
     * @param array<string, int> $marks
     */
    private function writeOpenedMarks(int $userId, string $kind, array $marks): void
    {
        $rows = [];
        foreach ($marks as $id => $at) {
            $rows[] = ['id' => (string) $id, 'at' => $at];
        }
        $this->configRepository->setValue(
            $userId,
            IamConfig::CONFIG_GROUP,
            self::openedSettingFor($kind),
            json_encode($rows, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function withOpenedLock(int $userId, string $kind, callable $work): mixed
    {
        $lock = $this->lockFactory->createLock(
            'iam-shared-opened-'.$userId.'-'.$kind,
            self::OPEN_LOCK_TTL_SECONDS,
        );
        $lock->acquire(true);
        try {
            return $work();
        } finally {
            $lock->release();
        }
    }

    private static function isAcceptableResourceId(string $resourceId): bool
    {
        // Conversation ids are numeric. Knowledge-folder ids are ownerId:groupKey.
        return 1 === preg_match('/^[A-Za-z0-9_:-]{1,128}$/', $resourceId);
    }

    /**
     * Number of resources of this kind that reached the user after they last
     * looked. Throws {@see UnknownResourceKindException} for an
     * unknown kind, like the list it is derived from.
     */
    public function countUnseen(int $userId, string $kind): int
    {
        $lastSeenAt = $this->lastSeenAt($userId, $kind);
        $opened = $this->openedMarks($userId, $kind);
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
     * @param array<string, int>                                                              $opened resource id => sharedAt when opened
     */
    public function rowIsNew(array $row, int $userId, int $lastSeenAt, array $opened): bool
    {
        if ($row['share']->getGrantedBy() === $userId) {
            return false;
        }
        if ($row['sharedAt'] <= $lastSeenAt) {
            return false;
        }

        return ($opened[$row['card']->id] ?? null) !== $row['sharedAt'];
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
