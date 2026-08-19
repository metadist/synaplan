<?php

declare(strict_types=1);

namespace App\Service;

/**
 * Deployment-level switch for the anonymous guest trial chat.
 *
 * Like {@see RegistrationConfig}, this is an environment flag because it is an
 * install-shape decision, not a runtime admin preference: an operator running
 * an SSO-/OIDC-only instance sets `GUEST_CHAT_ENABLED=false` so anonymous
 * visitors cannot consume AI (or upload files) without signing in, and the
 * trial's sign-up funnel (which such instances cannot serve) never shows
 * (issue #1517).
 *
 * Read at two seams:
 *   - the public runtime-config response (frontend sends unauthenticated
 *     visitors to /login instead of starting a guest session), and
 *   - {@see \App\Controller\GuestChatController} (server-side refusal of every
 *     guest endpoint, so disabling it is not merely cosmetic).
 *
 * Defaults ON when unset so existing self-host and cloud installs are unchanged.
 */
final readonly class GuestChatConfig
{
    public function isEnabled(): bool
    {
        $raw = trim((string) ($_ENV['GUEST_CHAT_ENABLED'] ?? ''));

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
