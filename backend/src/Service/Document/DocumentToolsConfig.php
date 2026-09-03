<?php

declare(strict_types=1);

namespace App\Service\Document;

use App\Repository\ConfigRepository;

/**
 * BCONFIG DOCUMENT_TOOLS.* — all flags default OFF / conservative.
 */
final readonly class DocumentToolsConfig
{
    public const CONFIG_GROUP = 'DOCUMENT_TOOLS';
    public const KEY_ENABLED = 'ENABLED';
    public const KEY_MAX_ITERATIONS = 'MAX_ITERATIONS';
    public const KEY_MAX_OPS_PER_TURN = 'MAX_OPS_PER_TURN';
    public const KEY_KEEP_REVISIONS = 'KEEP_REVISIONS';
    public const KEY_ALLOW_UPLOAD_EDIT = 'ALLOW_UPLOAD_EDIT';

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isEnabled(?int $userId = null): bool
    {
        return $this->flag(self::KEY_ENABLED, $userId, false);
    }

    public function allowUploadEdit(?int $userId = null): bool
    {
        return $this->isEnabled($userId) && $this->flag(self::KEY_ALLOW_UPLOAD_EDIT, $userId, false);
    }

    public function maxIterations(): int
    {
        return $this->int(self::KEY_MAX_ITERATIONS, 8);
    }

    public function maxOpsPerTurn(): int
    {
        return $this->int(self::KEY_MAX_OPS_PER_TURN, 24);
    }

    public function keepRevisions(): int
    {
        return $this->int(self::KEY_KEEP_REVISIONS, 10);
    }

    private function flag(string $setting, ?int $userId, bool $default): bool
    {
        if (null !== $userId && $userId > 0) {
            $perUser = $this->configRepository->getValue($userId, self::CONFIG_GROUP, $setting);
            if (null !== $perUser) {
                return filter_var($perUser, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
            }
        }
        $global = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
        if (null === $global) {
            return $default;
        }

        return filter_var($global, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? $default;
    }

    private function int(string $setting, int $default): int
    {
        $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, $setting);
        if (null === $raw || !is_numeric($raw)) {
            return $default;
        }

        return max(1, (int) $raw);
    }
}
