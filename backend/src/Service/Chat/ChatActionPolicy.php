<?php

declare(strict_types=1);

namespace App\Service\Chat;

use App\Repository\ConfigRepository;
use App\Service\Config\LayeredConfigResolver;

/**
 * Group policy switches for taking a chat out of Synaplan and for sharing it.
 *
 * Missing rows stay allowed: existing installs keep export and share until an
 * admin turns the switch off.
 */
final readonly class ChatActionPolicy
{
    public const GROUP = 'CHAT';
    public const EXPORT = 'EXPORT_ENABLED';
    public const SHARE = 'SHARE_ENABLED';

    public function __construct(
        private ConfigRepository $configRepository,
        private ?LayeredConfigResolver $layeredConfigResolver = null,
    ) {
    }

    public function canExport(?int $userId): bool
    {
        return $this->flag(self::EXPORT, $userId);
    }

    public function canShare(?int $userId): bool
    {
        return $this->flag(self::SHARE, $userId);
    }

    private function flag(string $setting, ?int $userId): bool
    {
        if (null !== $this->layeredConfigResolver) {
            return $this->layeredConfigResolver->resolveBool($userId, self::GROUP, $setting, true);
        }
        $raw = $this->configRepository->getValue(0, self::GROUP, $setting);
        if (null === $raw) {
            return true;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }
}
