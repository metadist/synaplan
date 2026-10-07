<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Repository\ConfigRepository;

/**
 * Live preview of HTML and SVG answers.
 *
 * On when CHAT_ARTIFACTS_ENABLED or the admin row is explicitly on, and neither
 * is explicitly off. A missing row and an empty env leave the preview absent.
 */
final readonly class ChatArtifactsConfig
{
    public const GROUP = 'CHAT_ARTIFACTS';
    public const ENABLED = 'ENABLED';

    public function __construct(
        private ConfigRepository $configRepository,
        private string $envValue = '',
    ) {
    }

    public function isEnabled(): bool
    {
        $env = $this->envValue;
        if ($this->isExplicitlyOff($env)) {
            return false;
        }
        $stored = $this->configRepository->getValue(0, self::GROUP, self::ENABLED);
        if (null !== $stored && $this->isExplicitlyOff($stored)) {
            return false;
        }
        if ($this->isExplicitlyOn($env)) {
            return true;
        }
        if (null !== $stored && $this->isExplicitlyOn($stored)) {
            return true;
        }

        // Missing env and missing row: the preview is absent. The full stack
        // sets CHAT_ARTIFACTS_ENABLED=1; the minimal stack leaves it empty.
        return false;
    }

    private function isExplicitlyOff(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['0', 'false', 'off', 'disabled', 'no'], true);
    }

    private function isExplicitlyOn(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'on', 'yes'], true);
    }
}
