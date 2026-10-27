<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Repository\ConfigRepository;
use App\Service\CloudWatch\WatchLimits;

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
    public const WATCHES_PER_PERSON = 'WATCHES_PER_PERSON';
    public const WATCHES_PER_INSTANCE = 'WATCHES_PER_INSTANCE';

    public function __construct(
        private ConfigRepository $configRepository,
        private string $envValue = '',
        private string $birdGlyph = '',
        private string $eyesGlyph = '',
    ) {
    }

    public function watchesPerPerson(): int
    {
        return $this->positive(self::WATCHES_PER_PERSON, WatchLimits::DEFAULT_PER_PERSON);
    }

    public function watchesPerInstance(): int
    {
        return $this->positive(self::WATCHES_PER_INSTANCE, WatchLimits::DEFAULT_PER_INSTANCE);
    }

    /**
     * @return array{bird: string|null, bird_eyes: string|null}
     */
    public function glyphs(): array
    {
        return [
            'bird' => '' === trim($this->birdGlyph) ? null : trim($this->birdGlyph),
            'bird_eyes' => '' === trim($this->eyesGlyph) ? null : trim($this->eyesGlyph),
        ];
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

    private function positive(string $key, int $default): int
    {
        $stored = $this->configRepository->getValue(0, self::GROUP, $key);
        if (!is_string($stored) || !ctype_digit(trim($stored))) {
            return $default;
        }
        $value = (int) trim($stored);

        return $value > 0 ? $value : $default;
    }

    private function isOn(string $value): bool
    {
        return in_array(strtolower(trim($value)), ['1', 'true', 'on', 'yes'], true);
    }
}
