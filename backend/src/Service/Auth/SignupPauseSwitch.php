<?php

declare(strict_types=1);

namespace App\Service\Auth;

use App\Repository\ConfigRepository;

/**
 * Kill switch for account creation on every path (email, Google, GitHub,
 * Apple), separate from the permanent {@see RegistrationConfig} toggle.
 *
 * Precedence, like RegistrationConfig:
 *
 *   1. `SIGNUP_PAUSED` when explicitly set in the environment.
 *   2. BCONFIG `SIGNUP.PAUSED`, flipped under Admin → Configuration →
 *      Authentication without a container roll — the point of the switch:
 *      during the 2026-10-24 burst the only way to stop account creation was
 *      an env edit plus a rolling restart of three nodes.
 *   3. Default: not paused.
 *
 * Existing accounts sign in as usual while paused; only *new* accounts are
 * refused, with the reason stated to the person.
 */
final readonly class SignupPauseSwitch
{
    public const CONFIG_GROUP = 'SIGNUP';
    public const KEY_PAUSED = 'PAUSED';
    public const ENV_VAR = 'SIGNUP_PAUSED';

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isPaused(): bool
    {
        $fromEnv = $this->envOverride();
        if (null !== $fromEnv) {
            return $fromEnv;
        }

        $stored = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_PAUSED);
        if (null === $stored || '' === $stored) {
            return false;
        }

        return filter_var($stored, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function envOverride(): ?bool
    {
        $raw = trim((string) ($_ENV[self::ENV_VAR] ?? ''));
        if ('' === $raw) {
            return null;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? false;
    }

    public function isLockedByEnv(): bool
    {
        return null !== $this->envOverride();
    }

    public function store(bool $paused): void
    {
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_PAUSED, $paused ? 'true' : 'false');
    }
}
