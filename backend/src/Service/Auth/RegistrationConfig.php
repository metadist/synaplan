<?php

declare(strict_types=1);

namespace App\Service\Auth;

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
 *
 * Besides on/off there is a mode ({@see mode()}): `open` is today's sign-up,
 * `admission` requires a paid plan or a sign-up code for every new account.
 * The public default is `open`; the hosted platform sets `admission`.
 */
final readonly class RegistrationConfig
{
    public const CONFIG_GROUP = 'ACCESS';
    public const KEY_ENABLED = 'REGISTRATION_ENABLED';
    public const KEY_MODE = 'REGISTRATION_MODE';

    public const ENV_VAR = 'REGISTRATION_ENABLED';
    public const ENV_VAR_MODE = 'REGISTRATION_MODE';

    /**
     * Anyone may create an account (today's behaviour, the public default).
     */
    public const MODE_OPEN = 'open';
    /**
     * A new account needs an admission: a paid plan (the account waits as
     * `pending_payment` until the invoice settles) or a sign-up code an
     * administrator issued. The hosted platform runs this mode.
     */
    public const MODE_ADMISSION = 'admission';
    public const MODES = [self::MODE_OPEN, self::MODE_ADMISSION];

    private const DEFAULT_ENABLED = true;
    private const DEFAULT_MODE = self::MODE_OPEN;

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

    /**
     * How a stranger gets an account: {@see MODE_OPEN} or {@see MODE_ADMISSION}.
     * Same precedence as {@see isEnabled()}: env pins, BCONFIG falls back,
     * default open. Irrelevant while {@see isEnabled()} is false.
     *
     * @return self::MODE_*
     */
    public function mode(): string
    {
        $fromEnv = $this->modeEnvOverride();
        if (null !== $fromEnv) {
            return $fromEnv;
        }

        $stored = self::normalizeMode($this->configRepository->getValue(0, self::CONFIG_GROUP, self::KEY_MODE));

        return $stored ?? self::DEFAULT_MODE;
    }

    public function isAdmissionMode(): bool
    {
        return self::MODE_ADMISSION === $this->mode();
    }

    /**
     * @return self::MODE_*|null
     */
    public function modeEnvOverride(): ?string
    {
        return self::normalizeMode($_ENV[self::ENV_VAR_MODE] ?? null);
    }

    public function isModeLockedByEnv(): bool
    {
        return null !== $this->modeEnvOverride();
    }

    /**
     * Persist the mode. Takes any string on purpose: the admin UI passes what
     * it received, and an unknown value must be refused here, not stored.
     *
     * @throws \InvalidArgumentException for a value outside {@see MODES}
     */
    public function storeMode(string $mode): void
    {
        if (!in_array($mode, self::MODES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown registration mode "%s"; expected one of %s', $mode, implode(', ', self::MODES)));
        }

        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_MODE, $mode);
    }

    /**
     * A recognised mode or null. An unknown or empty value is treated as
     * unset so a typo in .env cannot lock the install into a mode that does
     * not exist.
     *
     * @return self::MODE_*|null
     */
    private static function normalizeMode(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $mode = strtolower(trim($raw));

        return in_array($mode, self::MODES, true) ? $mode : null;
    }
}
