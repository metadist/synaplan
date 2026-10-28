<?php

declare(strict_types=1);

namespace App\AI\Pipeline;

/**
 * Rollout mode for `AI.USAGE_HOOK` (`03_contracts.md` §1.5, S1 AU11).
 */
enum UsageHookMode: string
{
    case Legacy = 'legacy';
    case Shadow = 'shadow';
    case On = 'on';

    public static function fromRaw(?string $raw): self
    {
        if (null === $raw) {
            return self::Legacy;
        }

        return self::tryFrom(strtolower(trim($raw))) ?? self::Legacy;
    }
}
