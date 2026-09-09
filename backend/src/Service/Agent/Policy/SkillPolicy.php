<?php

declare(strict_types=1);

namespace App\Service\Agent\Policy;

use App\Service\Multitask\Plan\Capability;
use App\Service\Runtime\RuntimeProfile;

/**
 * Resolves the capability list an assistant may plan. `deny` wins;
 * `allow = null` means everything the user may use today.
 */
final class SkillPolicy
{
    /**
     * @return list<string>|null null = unrestricted
     */
    public static function allowedCapabilities(RuntimeProfile $profile): ?array
    {
        $allow = $profile->skillAllow;
        $deny = $profile->skillDeny ?? [];
        if (null === $allow && [] === $deny) {
            return null;
        }

        $universe = $allow ?? Capability::values();
        if ([] === $deny) {
            return $universe;
        }

        return array_values(array_filter(
            $universe,
            static fn (string $name): bool => !in_array($name, $deny, true),
        ));
    }

    public static function isAllowed(RuntimeProfile $profile, string $capability): bool
    {
        $allowed = self::allowedCapabilities($profile);

        return null === $allowed || in_array($capability, $allowed, true);
    }
}
