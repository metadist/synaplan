<?php

declare(strict_types=1);

namespace App\Service\Config;

use App\Entity\Config;
use App\Entity\GroupConfig;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\GroupMemberRepository;
use App\Service\Iam\IamConfig;
use App\Service\Iam\Policy\PolicyAllowList;

/**
 * Resolves BCONFIG with an optional group layer.
 *
 * Flag off (C1): chain is exactly [user row?, global row?] and BGROUPCONFIG is
 * never read. Flag on: a blocked global row wins alone; otherwise
 * [user?, merged groups?, global?] for allow-listed keys only.
 */
final class LayeredConfigResolver
{
    /** @var array<string, list<string>> */
    private array $chainMemo = [];

    /** @var array<int, list<int>> */
    private array $groupIdsMemo = [];

    public function __construct(
        private readonly ConfigRepository $configRepository,
        private readonly GroupConfigRepository $groupConfigRepository,
        private readonly GroupMemberRepository $groupMemberRepository,
        private readonly IamConfig $iamConfig,
    ) {
    }

    /**
     * Candidate values in precedence order (first wins for resolve()).
     *
     * @return list<string>
     */
    public function chain(?int $userId, string $group, string $setting): array
    {
        $memoKey = ($userId ?? 0).'|'.$group.'|'.$setting;
        if (isset($this->chainMemo[$memoKey])) {
            return $this->chainMemo[$memoKey];
        }

        $user = null;
        if (null !== $userId && $userId > 0) {
            $user = $this->configRepository->getValue($userId, $group, $setting);
        }
        $globalRow = $this->configRepository->findByOwnerGroupAndSetting(0, $group, $setting);
        $global = $globalRow?->getValue();

        if (!$this->usesGroupLayer($userId, $group, $setting)) {
            $chain = $this->appendPresent([], $user, $global);

            return $this->chainMemo[$memoKey] = $chain;
        }

        if ($globalRow instanceof Config && $globalRow->isBlocked()) {
            return $this->chainMemo[$memoKey] = $this->appendPresent([], $global);
        }

        $merged = $this->mergedGroupValue($userId, $group, $setting);

        return $this->chainMemo[$memoKey] = $this->appendPresent([], $user, $merged, $global);
    }

    public function resolve(?int $userId, string $group, string $setting): ?string
    {
        $chain = $this->chain($userId, $group, $setting);

        return $chain[0] ?? null;
    }

    public function resolveBool(?int $userId, string $group, string $setting, bool $default): bool
    {
        $raw = $this->resolve($userId, $group, $setting);
        if (null === $raw) {
            return $default;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public function resolveInt(?int $userId, string $group, string $setting, int $default): int
    {
        $raw = $this->resolve($userId, $group, $setting);
        if (null === $raw || !is_numeric($raw)) {
            return $default;
        }

        return (int) $raw;
    }

    /**
     * Who supplied the winning value: user, group, or admin (global).
     */
    public function source(?int $userId, string $group, string $setting): ?string
    {
        $user = null;
        if (null !== $userId && $userId > 0) {
            $user = $this->configRepository->getValue($userId, $group, $setting);
        }
        $globalRow = $this->configRepository->findByOwnerGroupAndSetting(0, $group, $setting);
        $global = $globalRow?->getValue();

        if ($this->usesGroupLayer($userId, $group, $setting)
            && $globalRow instanceof Config
            && $globalRow->isBlocked()
        ) {
            return null !== $global ? 'admin' : null;
        }

        if (null !== $user) {
            return 'user';
        }
        if ($this->usesGroupLayer($userId, $group, $setting)
            && null !== $this->mergedGroupValue($userId, $group, $setting)
        ) {
            return 'group';
        }
        if (null !== $global) {
            return 'admin';
        }

        return null;
    }

    public function isLocked(string $group, string $setting, ?int $userId = null): bool
    {
        if (!$this->iamConfig->isGroupPoliciesEnabled($userId)) {
            return false;
        }
        $globalRow = $this->configRepository->findByOwnerGroupAndSetting(0, $group, $setting);

        return $globalRow instanceof Config && $globalRow->isBlocked();
    }

    /**
     * @return list<string>
     */
    public function allowedCatalogKeys(?int $userId): array
    {
        $raw = $this->resolve($userId, 'MODELS', 'ALLOWED');
        if (null === $raw || '' === trim($raw)) {
            return [];
        }

        return PolicyAllowList::decodeList($raw);
    }

    /**
     * Distinct DEFAULTMODEL values set by more than one of the user's groups.
     *
     * @return list<string>
     */
    public function defaultModelConflicts(?int $userId, string $setting): array
    {
        if (null === $userId || $userId <= 0 || !$this->usesGroupLayer($userId, 'DEFAULTMODEL', $setting)) {
            return [];
        }
        $rows = $this->orderedGroupRows($userId, 'DEFAULTMODEL', $setting);
        $byValue = [];
        foreach ($rows as $row) {
            $byValue[$row->getValue()][] = $row->getGroupId();
        }
        if (count($byValue) < 2) {
            return [];
        }

        return array_keys($byValue);
    }

    private function usesGroupLayer(?int $userId, string $group, string $setting): bool
    {
        if (null === $userId || $userId <= 0) {
            return false;
        }
        if (!PolicyAllowList::contains($group, $setting)) {
            return false;
        }

        return $this->iamConfig->isGroupPoliciesEnabled($userId);
    }

    private function mergedGroupValue(?int $userId, string $group, string $setting): ?string
    {
        if (null === $userId || $userId <= 0) {
            return null;
        }
        $rows = $this->orderedGroupRows($userId, $group, $setting);
        if ([] === $rows) {
            return null;
        }
        $values = array_map(static fn (GroupConfig $row): string => $row->getValue(), $rows);

        return PolicyAllowList::merge($group, $setting, $values);
    }

    /**
     * @return list<GroupConfig>
     */
    private function orderedGroupRows(int $userId, string $group, string $setting): array
    {
        $groupIds = $this->groupIdsOf($userId);
        if ([] === $groupIds) {
            return [];
        }
        $rows = $this->groupConfigRepository->getForGroups($groupIds, $group, $setting);
        $order = array_flip($groupIds);
        usort($rows, static function (GroupConfig $a, GroupConfig $b) use ($order): int {
            return ($order[$a->getGroupId()] ?? 0) <=> ($order[$b->getGroupId()] ?? 0);
        });

        return $rows;
    }

    /**
     * @return list<int>
     */
    private function groupIdsOf(int $userId): array
    {
        if (isset($this->groupIdsMemo[$userId])) {
            return $this->groupIdsMemo[$userId];
        }
        $ids = [];
        foreach ($this->groupMemberRepository->findByUserId($userId) as $member) {
            $ids[] = $member->getGroupId();
        }
        $ids = array_values(array_unique($ids));
        sort($ids, \SORT_NUMERIC);

        return $this->groupIdsMemo[$userId] = $ids;
    }

    /**
     * @param list<string> $chain
     *
     * @return list<string>
     */
    private function appendPresent(array $chain, ?string ...$values): array
    {
        foreach ($values as $value) {
            if (null !== $value) {
                $chain[] = $value;
            }
        }

        return $chain;
    }
}
