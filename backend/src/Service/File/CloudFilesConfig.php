<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Repository\ConfigRepository;

/**
 * Searchable-file state is off until an administrator turns it on.
 *
 * CLOUD_FILES_ENABLED=1 forces it on and CLOUD_FILES_ENABLED=0 forces it off.
 * An empty variable follows CLOUD_FILES.ENABLED, which is off when missing.
 */
final readonly class CloudFilesConfig
{
    public const GROUP = 'CLOUD_FILES';
    public const ENABLED = 'ENABLED';

    public function __construct(
        private ConfigRepository $configRepository,
        private string $envValue = '',
    ) {
    }

    public function isEnabled(): bool
    {
        if ($this->isOn($this->envValue)) {
            return true;
        }
        if ($this->isOff($this->envValue)) {
            return false;
        }
        $stored = $this->configRepository->getValue(0, self::GROUP, self::ENABLED);

        return is_string($stored) && $this->isOn($stored);
    }

    private function isOff(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['0', 'false', 'off', 'disabled', 'no'], true);
    }

    private function isOn(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }
}
