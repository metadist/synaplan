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

    public const DEFAULT_MAX_SOURCES = 50;
    public const DEFAULT_INSTRUCTIONS_MAX_CHARS = 4000;

    public function __construct(
        private ConfigRepository $configRepository,
        private string $envValue = '',
    ) {
    }

    /**
     * Null when PROJECTS_ENABLED does not pin the switch.
     */
    public function envOverride(): ?bool
    {
        $env = strtolower(trim($this->envValue));
        if (\in_array($env, ['1', 'true', 'on', 'yes'], true)) {
            return true;
        }

        return \in_array($env, ['0', 'false', 'off', 'no'], true) ? false : null;
    }

    public function isEnabled(): bool
    {
        $pinned = $this->envOverride();
        if (null !== $pinned) {
            return $pinned;
        }
        $stored = $this->configRepository->getValue(0, self::GROUP, self::ENABLED);

        return null !== $stored && \in_array(strtolower(trim($stored)), ['1', 'true', 'on', 'yes'], true);
    }

    public function maxSources(): int
    {
        return $this->positiveInt(self::MAX_SOURCES, self::DEFAULT_MAX_SOURCES);
    }

    public function instructionsMaxChars(): int
    {
        return $this->positiveInt(self::INSTRUCTIONS_MAX_CHARS, self::DEFAULT_INSTRUCTIONS_MAX_CHARS);
    }

    private function positiveInt(string $setting, int $default): int
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, $setting);
        $value = null === $stored ? 0 : (int) $stored;

        return $value > 0 ? $value : $default;
    }
}
