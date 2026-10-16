<?php

declare(strict_types=1);

namespace App\Service\Agent\Policy;

use App\Service\Multitask\Plan\Capability;
use App\Service\Runtime\RuntimeProfile;

/**
 * Assistant limits that the skill list does not express.
 *
 * `tools.internet = false` is a hard opt-out. Skill allow/deny can still
 * leave `web_search` in the plan, so the planner and the search runner both
 * have to honour the flag or a planned search leaves the machine.
 */
final class AssistantPlanLimits
{
    private function __construct()
    {
    }

    /**
     * @param list<string>|null $allowed null = every skill the gate already allowed
     *
     * @return list<string>|null
     */
    public static function withoutDisabledTools(?array $allowed, RuntimeProfile $profile): ?array
    {
        if (false !== ($profile->toolFlags['tool_internet'] ?? null)) {
            return $allowed;
        }

        $universe = $allowed ?? Capability::values();

        return array_values(array_filter(
            $universe,
            static fn (string $name): bool => Capability::WebSearch->value !== $name,
        ));
    }

    public static function internetDisabled(?RuntimeProfile $profile): bool
    {
        return $profile instanceof RuntimeProfile
            && false === ($profile->toolFlags['tool_internet'] ?? null);
    }

    public static function instructions(?RuntimeProfile $profile): ?string
    {
        if (!$profile instanceof RuntimeProfile) {
            return null;
        }

        $text = trim((string) $profile->systemPrompt);

        return '' === $text ? null : $text;
    }
}
