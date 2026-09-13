<?php

declare(strict_types=1);

namespace App\Service\Agent\Policy;

use App\Service\Multitask\Plan\Capability;
use App\Service\Runtime\RuntimeProfile;

/**
 * Opt-in gate for capabilities that must not appear on existing assistants.
 *
 * Track-2 {@see SkillPolicy} treats `allow = null` as unrestricted. File work
 * (`code_run`) is the opposite: an assistant is excluded until the owner
 * lists `code_run` on the definition. A turn with no assistant (the user's
 * own chat) is not gated here.
 */
final class AssistantSkillGate
{
    public const REFUSAL = 'This assistant is not allowed to do file work.';
    public const REASON = 'assistant_forbids_code_run';

    private function __construct()
    {
    }

    public static function allows(?RuntimeProfile $assistant, string $capability): bool
    {
        if (Capability::CodeRun->value !== $capability) {
            return null === $assistant || SkillPolicy::isAllowed($assistant, $capability);
        }

        if (null === $assistant) {
            return true;
        }

        $deny = $assistant->skillDeny ?? [];
        if (\in_array(Capability::CodeRun->value, $deny, true)) {
            return false;
        }

        $allow = $assistant->skillAllow;
        if (null === $allow || [] === $allow) {
            return false;
        }

        return \in_array(Capability::CodeRun->value, $allow, true);
    }

    /**
     * Strip `code_run` from an assistant's capability list unless opted in.
     * `null` (unrestricted user chat) is left unchanged.
     *
     * @param list<string>|null $allowed
     *
     * @return list<string>|null
     */
    public static function filterCapabilities(?array $allowed, ?RuntimeProfile $assistant): ?array
    {
        if (null === $assistant || self::allows($assistant, Capability::CodeRun->value)) {
            return $allowed;
        }

        $universe = $allowed ?? Capability::values();

        return array_values(array_filter(
            $universe,
            static fn (string $name): bool => Capability::CodeRun->value !== $name,
        ));
    }
}
