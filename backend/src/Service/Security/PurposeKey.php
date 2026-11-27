<?php

declare(strict_types=1);

namespace App\Service\Security;

/**
 * Derives one key per purpose from the application secret (HKDF-SHA256).
 *
 * A token family that signs with its own key cannot be confused with another
 * family, even when both use the same payload format. The purpose string is
 * part of the format: changing it invalidates everything signed under it.
 */
final class PurposeKey
{
    public const ACCESS_TOKEN = 'synaplan/access-token/v1';
    public const OAUTH_STATE = 'synaplan/oauth-state/v1';
    public const NATIVE_AUTH_HANDOFF = 'synaplan/native-auth-handoff/v1';

    private const KEY_BYTES = 32;

    /**
     * @return string 32 raw bytes
     */
    public static function derive(#[\SensitiveParameter] string $secret, string $purpose): string
    {
        if ('' === $secret) {
            throw new \LogicException('APP_SECRET is empty; no signing key can be derived.');
        }
        if ('' === $purpose) {
            throw new \InvalidArgumentException('A key needs a purpose.');
        }

        return hash_hkdf('sha256', $secret, self::KEY_BYTES, $purpose);
    }
}
