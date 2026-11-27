<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Repository\ConfigRepository;

/**
 * The switches of the projects feature. Off means every project route
 * answers 404 `feature_not_configured`, and a chat in a project is treated
 * as a plain chat (no pinned folder, no instructions).
 */
final readonly class ProjectsConfig
{
    public const GROUP = 'PROJECTS';
    public const ENABLED = 'ENABLED';
    public const MAX_SOURCES = 'MAX_SOURCES';
    public const INSTRUCTIONS_MAX_CHARS = 'INSTRUCTIONS_MAX_CHARS';
    public const BOARD_ENABLED = 'BOARD_ENABLED';
    public const MAX_ITEMS = 'MAX_ITEMS';

    public const DEFAULT_MAX_SOURCES = 50;
    public const DEFAULT_INSTRUCTIONS_MAX_CHARS = 4000;
    public const DEFAULT_MAX_ITEMS = 1000;

    private const ON = ['1', 'true', 'on', 'yes'];
    private const OFF = ['0', 'false', 'off', 'no'];

    public function __construct(
        private ConfigRepository $configRepository,
        private string $envValue = '',
        private string $boardEnvValue = '',
    ) {
    }

    /**
     * Null when PROJECTS_ENABLED does not pin the switch.
     */
    public function envOverride(): ?bool
    {
        return $this->pinned($this->envValue);
    }

    public function isEnabled(): bool
    {
        return $this->pinned($this->envValue) ?? $this->storedOn(self::ENABLED);
    }

    /**
     * The project board (items, `PROJECTS.BOARD_ENABLED`). It needs projects:
     * with projects off the board is off too. PROJECTS_BOARD_ENABLED pins it.
     */
    public function isBoardEnabled(): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        return $this->pinned($this->boardEnvValue) ?? $this->storedOn(self::BOARD_ENABLED);
    }

    public function maxItems(): int
    {
        return $this->positiveInt(self::MAX_ITEMS, self::DEFAULT_MAX_ITEMS);
    }

    public function maxSources(): int
    {
        return $this->positiveInt(self::MAX_SOURCES, self::DEFAULT_MAX_SOURCES);
    }

    public function instructionsMaxChars(): int
    {
        return $this->positiveInt(self::INSTRUCTIONS_MAX_CHARS, self::DEFAULT_INSTRUCTIONS_MAX_CHARS);
    }

    private function pinned(string $envValue): ?bool
    {
        $env = strtolower(trim($envValue));
        if (\in_array($env, self::ON, true)) {
            return true;
        }

        return \in_array($env, self::OFF, true) ? false : null;
    }

    private function storedOn(string $setting): bool
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, $setting);

        return null !== $stored && \in_array(strtolower(trim($stored)), self::ON, true);
    }

    private function positiveInt(string $setting, int $default): int
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, $setting);
        $value = null === $stored ? 0 : (int) $stored;

        return $value > 0 ? $value : $default;
    }
}
