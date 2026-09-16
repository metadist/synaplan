<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\ConfigRepository;

/**
 * Switch for the anonymous guest trial chat.
 *
 * Same precedence as {@see RegistrationConfig}:
 *
 *   1. `GUEST_CHAT_ENABLED` when explicitly set (operator pin, wins),
 *   2. BCONFIG `ACCESS.GUEST_CHAT_ENABLED` (setup wizard / admin UI),
 *   3. the built-in default ON, unchanged.
 *
 * An operator running an SSO-/OIDC-only instance sets `GUEST_CHAT_ENABLED=false`
 * so anonymous visitors cannot consume AI (or upload files) without signing in,
 * and the trial's sign-up funnel — which such instances cannot serve — never
 * shows (issue #1517).
 *
 * Read at two seams:
 *   - the public runtime-config response (frontend sends unauthenticated
 *     visitors to /login instead of starting a guest session), and
 *   - {@see \App\Controller\GuestChatController} (server-side refusal of every
 *     guest endpoint, so disabling it is not merely cosmetic).
 */
final readonly class GuestChatConfig
{
    public const CONFIG_GROUP = 'ACCESS';
    public const KEY_ENABLED = 'GUEST_CHAT_ENABLED';

    public const ENV_VAR = 'GUEST_CHAT_ENABLED';

    /**
     * Machine-readable code carried by every 403 the disabled trial produces
     * (guest endpoints and the stream endpoint's guest path alike), mirroring
     * REGISTRATION_DISABLED from issue #462.
     */
    public const DISABLED_CODE = 'GUEST_CHAT_DISABLED';

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
     * would have no effect here.
     */
    public function envOverride(): ?bool
    {
        $raw = trim((string) ($_ENV[self::ENV_VAR] ?? ''));
        if ('' === $raw) {
            return null;
        }

        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? self::DEFAULT_ENABLED;
    }

    public function isLockedByEnv(): bool
    {
        return null !== $this->envOverride();
    }

    public function store(bool $enabled): void
    {
        $this->configRepository->setValue(0, self::CONFIG_GROUP, self::KEY_ENABLED, $enabled ? 'true' : 'false');
    }
}
