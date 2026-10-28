<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

use App\Repository\ConfigRepository;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Reads the global `AI.USAGE_HOOK` setting (`legacy` | `shadow` | `on`,
 * `03_contracts.md` §1.5).
 */
final class UsageHookConfig implements ResetInterface
{
    public const CONFIG_GROUP = 'AI';
    public const KEY_USAGE_HOOK = 'USAGE_HOOK';

    private static ?UsageHookMode $override = null;

    public function __construct(
        private readonly ?ConfigRepository $configRepository = null,
    ) {
    }

    public static function setModeOverride(?UsageHookMode $mode): void
    {
        self::$override = $mode;
    }

    public static function modeOverride(): ?UsageHookMode
    {
        return self::$override;
    }

    public function mode(): UsageHookMode
    {
        if (null !== self::$override) {
            return self::$override;
        }

        if (null === $this->configRepository) {
            return UsageHookMode::Legacy;
        }

        try {
            $raw = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_USAGE_HOOK);
        } catch (\Throwable) {
            return UsageHookMode::Legacy;
        }

        return UsageHookMode::fromRaw($raw);
    }

    public function isLegacy(): bool
    {
        return UsageHookMode::Legacy === $this->mode();
    }

    public function isShadow(): bool
    {
        return UsageHookMode::Shadow === $this->mode();
    }

    public function isOn(): bool
    {
        return UsageHookMode::On === $this->mode();
    }

    public function reset(): void
    {
        self::$override = null;
    }
}
