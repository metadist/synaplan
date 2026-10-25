<?php

declare(strict_types=1);

namespace App\Service\Research\Run;

use App\Repository\ConfigRepository;

/**
 * Research is off until an administrator turns it on.
 *
 * RESEARCH_ENABLED=1 forces it on and RESEARCH_ENABLED=0 forces it off.
 * An empty variable follows the RESEARCH.ENABLED row, which new installs
 * seed as off.
 */
final readonly class ResearchConfig
{
    public const GROUP = 'RESEARCH';
    public const ENABLED = 'ENABLED';
    public const MAX_ACTIVE_RUNS = 'MAX_ACTIVE_RUNS';
    public const MAX_ACTIVE_THOROUGH = 'MAX_ACTIVE_THOROUGH_PER_USER';
    public const LEAD_MODE = 'LEAD_MODE';
    public const TOOL_MODE_MODELS = 'TOOL_MODE_MODELS';

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

        return null !== $stored && $this->isOn($stored);
    }

    public function maxActiveRuns(): int
    {
        return $this->bounded(self::MAX_ACTIVE_RUNS, 5, 1, 100);
    }

    public function maxActiveThorough(): int
    {
        return $this->bounded(self::MAX_ACTIVE_THOROUGH, 1, 0, 10);
    }

    /** auto, tools or rounds. Anything else is auto, so the model list decides. */
    public function leadMode(): string
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, self::LEAD_MODE);
        $mode = is_string($stored) ? strtolower(trim($stored)) : 'auto';

        return in_array($mode, ['auto', 'tools', 'rounds'], true) ? $mode : 'auto';
    }

    /**
     * Catalog keys allowed to call tools. A missing or unreadable row is empty,
     * so every model stays in round mode.
     *
     * @return list<string>
     */
    public function toolModeModels(): array
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, self::TOOL_MODE_MODELS);
        if (!is_string($stored) || '' === trim($stored)) {
            return [];
        }
        try {
            $decoded = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $keys = [];
        foreach ($decoded as $key) {
            if (is_string($key) && '' !== trim($key)) {
                $keys[] = strtolower(trim($key));
            }
        }

        return $keys;
    }

    private function bounded(string $setting, int $default, int $min, int $max): int
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, $setting);
        if (!is_string($stored) || !ctype_digit($stored)) {
            return $default;
        }

        return max($min, min($max, (int) $stored));
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
