<?php

declare(strict_types=1);

namespace App\Service\Feature;

/**
 * Environment pin for BCONFIG feature flags.
 *
 * Every wave feature flag (IAM, assistants, tools, workflows, platform links,
 * document tools, desktop, bundle, URL watch, module gates) is stored in
 * BCONFIG and editable under Operate → System configuration → Features. An
 * automated deployment (Helm chart, compose stack, CI image) cannot click that
 * toggle, so it may pin a flag with an environment variable instead:
 *
 *   FEATURE_<GROUP>_<SETTING>=false   → the feature is OFF, whatever BCONFIG says
 *   FEATURE_<GROUP>_<SETTING>=true    → the feature is ON, whatever BCONFIG says
 *   (unset or empty)                  → BCONFIG decides (seeded ON since 4.8)
 *
 * The name is derived from the BCONFIG key: `IAM.SHARING_ENABLED` becomes
 * `FEATURE_IAM_SHARING_ENABLED`, `MODULES.GATE_TIKA` becomes
 * `FEATURE_MODULES_GATE_TIKA`. The same name is the field key in the admin UI,
 * which then shows the toggle as locked and names the variable to remove.
 *
 * Precedence mirrors {@see \App\Service\RegistrationConfig}: an explicit
 * environment value wins over per-user, group and global BCONFIG rows. An
 * unrecognised value (anything `filter_var` cannot read as a boolean) is
 * ignored rather than guessed, so a typo never silently disables a feature.
 */
final readonly class FeatureFlagEnv
{
    public const ENV_PREFIX = 'FEATURE_';

    /**
     * @param array<string, string>|null $env fixed environment for tests; null reads the process environment
     */
    public function __construct(
        private ?array $env = null,
    ) {
    }

    /** `IAM` + `SHARING_ENABLED` → `FEATURE_IAM_SHARING_ENABLED`; `TOOLS` + `POLICY.read` → `FEATURE_TOOLS_POLICY_READ`. */
    public static function envVarFor(string $group, string $setting): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '_', $group.'_'.$setting) ?? '';

        return self::ENV_PREFIX.strtoupper(trim($normalized, '_'));
    }

    /**
     * The pinned value, or null when the environment does not pin this flag.
     */
    public function forced(string $group, string $setting): ?bool
    {
        return $this->forcedByName(self::envVarFor($group, $setting));
    }

    public function forcedByName(string $envVar): ?bool
    {
        $raw = $this->read($envVar);
        if (null === $raw) {
            return null;
        }
        $raw = trim($raw);
        if ('' === $raw) {
            return null;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE);
    }

    public function isPinned(string $group, string $setting): bool
    {
        return null !== $this->forced($group, $setting);
    }

    private function read(string $name): ?string
    {
        if (null !== $this->env) {
            return $this->env[$name] ?? null;
        }

        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        return is_string($value) ? $value : null;
    }
}
