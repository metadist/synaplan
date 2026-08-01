<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deployment-level switch for local email/password self-registration.
 *
 * Unlike the DB-backed admin toggles (e.g. {@see UsageTaximeterConfig}), this is
 * an environment flag because it is an install-shape decision, not a runtime
 * admin preference: an operator running an SSO-/OIDC-only instance sets
 * `REGISTRATION_ENABLED=false` so the app offers no local sign-up (issue #462).
 *
 * Read at two seams:
 *   - the public runtime-config response (frontend hides the sign-up link and
 *     guards the /register route), and
 *   - {@see \App\Controller\AuthController::register()} (server-side refusal, so
 *     disabling it is not merely cosmetic).
 *
 * Defaults ON when unset so existing self-host and cloud installs are unchanged.
 */
final readonly class RegistrationConfig
{
    public function isEnabled(): bool
    {
        $raw = trim((string) ($_ENV['REGISTRATION_ENABLED'] ?? ''));

        // Unset or empty keeps the safe default (enabled), so existing installs
        // that never set the flag are unaffected.
        if ('' === $raw) {
            return true;
        }

        // Only an explicit falsey value ('false'/'0'/'off'/'no') disables it;
        // an unrecognized value also falls back to the safe default.
        return filter_var($raw, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true;
    }
}
