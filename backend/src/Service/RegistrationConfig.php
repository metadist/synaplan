<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ConfigRepository;

/**
 * Switch for local email/password self-registration.
 *
 * Precedence, in this order:
 *
 *   1. `REGISTRATION_ENABLED` when explicitly set — an operator who pinned the
 *      value in .env keeps a deterministic install, and nothing in the product
 *      can silently move it.
 *   2. BCONFIG `ACCESS.REGISTRATION_ENABLED`, written by the first-run setup
 *      wizard and editable under Admin → Configuration.
 *   3. The built-in default ON, unchanged, so existing installs that never set
 *      either are unaffected.
 *
 * The env layer is why this is not a plain DB toggle: an SSO-/OIDC-only instance
 * sets `REGISTRATION_ENABLED=false` so the app offers no local sign-up at all
 * (issue #462), and that decision must not be overridable from the UI.
 *
 * Read at two seams:
 *   - the public runtime-config response (frontend hides the sign-up link and
 *     guards the /register route), and
 *   - {@see \App\Controller\AuthController::register()} (server-side refusal, so
 *     disabling it is not merely cosmetic).
 */
final readonly class RegistrationConfig
{
    public const CONFIG_GROUP = 'ACCESS';
    public const KEY_ENABLED = 'REGISTRATION_ENABLED';

    public const ENV_VAR = 'REGISTRATION_ENABLED';

    private const DEFAULT_ENABLED = true;

    public function __construct(
        private ConfigRepository $configRepository,
    ) {
    }

    public function isEnabled(): bool
    {
        $fromEnv = $this->envOverride();
        if (null !== $fromEnv) {
            return $fromEnv;
        }

        $stored = $this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_ENABLED);
        if (null !== $stored && '' !== $stored) {
            return filter_var($stored, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
        }

        return self::DEFAULT_ENABLED;
    }

    /**
     * The explicit environment value, or null when unset/empty. Public so the
     * admin UI and the setup wizard can tell the operator that a stored value
     * would have no effect here, instead of showing a toggle that silently does
     * nothing.
     */
    public function envOverride(): ?bool
    {
        $raw = trim((string) ($_ENV[self::ENV_VAR] ?? ''));
        if ('' === $raw) {
            return null;
        }

        // Only an explicit falsey value ('false'/'0'/'off'/'no') disables it;
        // an unrecognized value falls back to the safe default.
        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }

    public function isLockedByEnv(): bool
    {
        return null !== $this->envOverride();
    }

    /**
     * Persist the install-wide value. A no-op in effect while the environment
     * pins the flag — the row is still written so the value survives the
     * operator later removing the env var.
     */
    public function store(bool $enabled): void
    {
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_ENABLED, $enabled ? 'true' : 'false');
    }
}
