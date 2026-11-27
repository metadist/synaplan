<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Refuses a production start with an APP_SECRET anyone can look up.
 *
 * Sessions, access tokens, widget signatures and the keys that encrypt stored
 * provider credentials are all derived from APP_SECRET. The value in
 * `.env.example` is public, so an installation that kept it hands every one of
 * those to whoever reads the repository.
 */
final class AppSecretGuard
{
    /** Prefixes of the values shipped in `.env.example` and the entrypoint's fallback `.env`. */
    private const EXAMPLE_PREFIXES = ['changeme', 'change_me', 'change-me'];

    public static function isUsable(string $secret): bool
    {
        $normalized = strtolower(trim($secret));
        if ('' === $normalized) {
            return false;
        }

        return array_all(
            self::EXAMPLE_PREFIXES,
            static fn (string $prefix): bool => !str_starts_with($normalized, $prefix),
        );
    }

    public static function assertUsable(string $secret): void
    {
        if (self::isUsable($secret)) {
            return;
        }

        throw new UnusableAppSecretException('APP_SECRET is empty or still the example value. Set a random one (openssl rand -hex 32) in the environment and start again. On an installation that already ran with the example value, stored provider keys must be entered again afterwards and everyone signs in again.');
    }
}
