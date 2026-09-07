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

        return $at;
    }

    /**
     * Number of resources of this kind that reached the user after they last
     * looked. Throws {@see UnknownResourceKindException} for an
     * unknown kind, like the list it is derived from.
     */
    public function countUnseen(int $userId, string $kind): int
    {
        $lastSeenAt = $this->lastSeenAt($userId, $kind);
        $count = 0;
        foreach ($this->shareService->listSharedWith($userId, $kind) as $row) {
            if ($row['share']->getGrantedBy() === $userId) {
                continue;
            }
            if ($row['sharedAt'] > $lastSeenAt) {
                ++$count;
            }
        }

        return $count;
    }

    private static function settingFor(string $kind): string
    {
        return self::SETTING_PREFIX.$kind;
    }
}
