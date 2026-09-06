<?php

declare(strict_types=1);

namespace App\Service\Iam\Policy;

use App\Entity\Group;
use App\Entity\Model;
use App\Entity\User;
use App\Model\ModelCatalog;
use App\Repository\ConfigRepository;
use App\Repository\GroupConfigRepository;
use App\Repository\ModelRepository;
use App\Service\Config\LayeredConfigResolver;
use App\Service\Iam\AuditLogWriter;

/**
 * Admin read/write for group policy rows and global locks.
 */
final readonly class GroupPolicyService
{
    public function __construct(
        private LayeredConfigResolver $resolver,
        private GroupConfigRepository $groupConfigRepository,
        private ConfigRepository $configRepository,
        private ModelRepository $modelRepository,
        private AuditLogWriter $auditLogWriter,
    ) {
    }

    /**
     * @return array{settings: array<string, array{value: mixed, source: string|null, locked: bool}>, conflicts: array<string, list<string>>}
     */
    public function getGroupConfig(int $groupId, User $actor): array
    {
        $settings = [];
        $conflicts = [];
        foreach (PolicyAllowList::keys() as $key) {
            $parts = PolicyAllowList::split($key);
            if (null === $parts) {
                continue;
            }
            $raw = $this->groupConfigRepository->getValue($groupId, $parts['group'], $parts['setting']);
            $global = $this->configRepository->getValue(0, $parts['group'], $parts['setting']);
            $source = null;
            if (null !== $raw) {
                $source = 'group';
            } elseif (null !== $global) {
                $source = 'admin';
            }
            $settings[$key] = [
                'value' => $this->decodeForApi($parts['group'], $parts['setting'], $raw ?? $global),
                'source' => $source,
                'locked' => $this->resolver->isLocked($parts['group'], $parts['setting'], (int) $actor->getId()),
            ];
            if (str_starts_with($key, 'DEFAULTMODEL.')) {
                $distinct = $this->distinctGroupValues($parts['group'], $parts['setting']);
                if (count($distinct) > 1) {
                    $conflicts[$key] = $distinct;
                }
            }
        }

        return ['settings' => $settings, 'conflicts' => $conflicts];
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{settings: array<string, array{value: mixed, source: string|null, locked: bool}>, conflicts: array<string, list<string>>}
     */
    public function putGroupConfig(Group $group, array $body, User $actor, string $ip = ''): array
    {
        $groupId = (int) $group->getId();
        foreach ($body as $key => $value) {
            $parts = PolicyAllowList::split($key);
            if (null === $parts) {
                throw new \InvalidArgumentException(sprintf('Unknown policy key "%s".', $key));
            }
            if (null === $value || '' === $value || [] === $value) {
                $this->groupConfigRepository->deleteValue($groupId, $parts['group'], $parts['setting']);
                continue;
            }
            $stored = $this->encodeFromApi($parts['group'], $parts['setting'], $value);
            $this->groupConfigRepository->setValue($groupId, $parts['group'], $parts['setting'], $stored);
        }

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'policy.set',
            'group',
            (string) $groupId,
            ['keys' => array_keys($body)],
            $ip,
        );

        return $this->getGroupConfig($groupId, $actor);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, bool>
     */
    public function setLocks(array $body, User $actor, string $ip = ''): array
    {
        $locked = [];
        foreach ($body as $key => $on) {
            $parts = PolicyAllowList::split($key);
            if (null === $parts) {
                throw new \InvalidArgumentException(sprintf('Unknown policy key "%s".', $key));
            }
            $blocked = filter_var($on, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
            if (null === $blocked) {
                $blocked = (bool) $on;
            }
            $row = $this->configRepository->findByOwnerGroupAndSetting(0, $parts['group'], $parts['setting']);
            if (null === $row) {
                $this->configRepository->setValue(0, $parts['group'], $parts['setting'], '');
                $row = $this->configRepository->findByOwnerGroupAndSetting(0, $parts['group'], $parts['setting']);
            }
            if (null === $row) {
                continue;
            }
            $row->setBlocked($blocked);
            $this->configRepository->save($row);
            $locked[$key] = $blocked;
        }

        $this->auditLogWriter->record(
            (int) $actor->getId(),
            'policy.lock',
            'config',
            'locks',
            ['locks' => $locked],
            $ip,
        );

        return $locked;
    }

    /**
     * @return array<string, bool>
     */
    public function listLocks(?int $userId): array
    {
        $out = [];
        foreach (PolicyAllowList::keys() as $key) {
            $parts = PolicyAllowList::split($key);
            if (null === $parts) {
                continue;
            }
            $out[$key] = $this->resolver->isLocked($parts['group'], $parts['setting'], $userId);
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $models
     *
     * @return list<array<string, mixed>>
     */
    public function filterModelsByAllowList(?int $userId, array $models): array
    {
        $allowed = $this->resolver->allowedCatalogKeys($userId);
        if ([] === $allowed) {
            return $models;
        }
        $allowedSet = [];
        foreach ($allowed as $key) {
            $allowedSet[strtolower($key)] = true;
            $bid = ModelCatalog::findBidByKey($key);
            if (null !== $bid) {
                $allowedSet['id:'.$bid] = true;
            }
        }
        $kept = [];
        foreach ($models as $model) {
            $id = isset($model['id']) ? (int) $model['id'] : 0;
            $service = (string) ($model['service'] ?? '');
            $providerId = (string) ($model['providerId'] ?? '');
            $tag = (string) ($model['tag'] ?? '');
            $catalogKey = strtolower(self::catalogKey($service, $providerId, $tag));
            $shortKey = strtolower(self::shortCatalogKey($service, $providerId));
            if (isset($allowedSet[$catalogKey]) || isset($allowedSet[$shortKey]) || isset($allowedSet['id:'.$id])) {
                $kept[] = $model;
            }
        }

        return $kept;
    }

    public function isModelAllowed(?int $userId, int $modelId): bool
    {
        $allowed = $this->resolver->allowedCatalogKeys($userId);
        if ([] === $allowed) {
            return true;
        }
        $model = $this->modelRepository->find($modelId);
        if (!$model instanceof Model) {
            return false;
        }
        $catalogKey = strtolower(self::catalogKey($model->getService(), $model->getProviderId(), $model->getTag()));
        $shortKey = strtolower(self::shortCatalogKey($model->getService(), $model->getProviderId()));
        foreach ($allowed as $key) {
            $needle = strtolower($key);
            if ($needle === $catalogKey || $needle === $shortKey) {
                return true;
            }
            if (ModelCatalog::findBidByKey($key) === $modelId) {
                return true;
            }
        }

        return false;
    }

    public function catalogKeyForModelId(int $modelId): ?string
    {
        $model = $this->modelRepository->find($modelId);
        if (!$model instanceof Model) {
            return null;
        }

        return self::catalogKey($model->getService(), $model->getProviderId(), $model->getTag());
    }

    public static function catalogKey(string $service, string $providerId, string $tag): string
    {
        return self::shortCatalogKey($service, $providerId).':'.strtolower($tag);
    }

    public static function shortCatalogKey(string $service, string $providerId): string
    {
        return strtolower($service).':'.strtolower(str_replace(':', '-', $providerId));
    }

    public function modelIdFromStored(string $raw): ?int
    {
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }
        if (is_numeric($raw)) {
            $id = (int) $raw;

            return $id > 0 ? $id : null;
        }

        return ModelCatalog::findBidByKey($raw);
    }

    public function validateStoredValue(string $group, string $setting, string $stored): void
    {
        if ('DEFAULTMODEL' === $group) {
            if (null === $this->modelIdFromStored($stored)) {
                throw new \InvalidArgumentException(sprintf('Invalid catalog key for %s.%s.', $group, $setting));
            }

            return;
        }
        if ('MODELS' === $group && 'ALLOWED' === $setting) {
            foreach (PolicyAllowList::decodeList($stored) as $key) {
                if (null === ModelCatalog::findBidByKey($key) && !$this->liveModelMatches($key)) {
                    throw new \InvalidArgumentException(sprintf('Invalid catalog key "%s".', $key));
                }
            }

            return;
        }
        if ('RATELIMITS' === $group && 'TIER' === $setting) {
            if (!in_array(strtoupper($stored), PolicyAllowList::TIERS, true)) {
                throw new \InvalidArgumentException('Rate-limit tier must be NEW, PRO, TEAM or BUSINESS.');
            }
        }
    }

    private function liveModelMatches(string $key): bool
    {
        $needle = strtolower($key);
        foreach ($this->modelRepository->findBy(['active' => 1]) as $model) {
            $full = strtolower(self::catalogKey($model->getService(), $model->getProviderId(), $model->getTag()));
            $short = strtolower(self::shortCatalogKey($model->getService(), $model->getProviderId()));
            if ($needle === $full || $needle === $short) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private function distinctGroupValues(string $group, string $setting): array
    {
        $values = [];
        foreach ($this->groupConfigRepository->findByGroupAndSetting($group, $setting) as $row) {
            $values[$row->getValue()] = true;
        }

        return array_keys($values);
    }

    private function decodeForApi(string $group, string $setting, ?string $raw): mixed
    {
        if (null === $raw) {
            return null;
        }
        if ('MODELS' === $group && 'ALLOWED' === $setting) {
            return PolicyAllowList::decodeList($raw);
        }
        if (PolicyAllowList::isBoolKey($group, $setting)) {
            return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
        }

        return $raw;
    }

    private function encodeFromApi(string $group, string $setting, mixed $value): string
    {
        if ('MODELS' === $group && 'ALLOWED' === $setting) {
            if (is_string($value)) {
                $list = PolicyAllowList::decodeList($value);
            } elseif (is_array($value)) {
                $list = [];
                foreach ($value as $item) {
                    if (is_string($item) && '' !== trim($item)) {
                        $list[] = trim($item);
                    }
                }
            } else {
                throw new \InvalidArgumentException('MODELS.ALLOWED must be a list of catalog keys.');
            }
            $stored = json_encode($list, \JSON_THROW_ON_ERROR);
            $this->validateStoredValue($group, $setting, $stored);

            return $stored;
        }
        if (PolicyAllowList::isBoolKey($group, $setting)) {
            $bool = filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
            if (null === $bool) {
                throw new \InvalidArgumentException(sprintf('%s.%s must be true or false.', $group, $setting));
            }

            return $bool ? '1' : '0';
        }
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException(sprintf('%s.%s has an invalid value.', $group, $setting));
        }
        $stored = is_int($value) ? (string) $value : trim($value);
        $this->validateStoredValue($group, $setting, $stored);

        return $stored;
    }
}
