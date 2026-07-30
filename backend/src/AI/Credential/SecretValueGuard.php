<?php

declare(strict_types=1);

namespace App\AI\Credential;

/**
 * Recognizes values that look like a secret but cannot be one: template
 * placeholders shipped in `.env.example` and the masked value the admin UI
 * displays instead of a stored key.
 *
 * Without this guard a stock install with an untouched `.env` reports its
 * providers as configured (and persists the placeholder encrypted in BCONFIG),
 * and an API client echoing back the masked display value overwrites a working
 * key with bullet characters.
 */
final class SecretValueGuard
{
    /** The character {@see ProviderKeyStore::mask()} pads with. */
    public const MASK_CHAR = '•';

    /**
     * Values from documentation/templates. Deliberately narrow: short dummy keys
     * such as "test-key" are what integration environments legitimately use, so
     * only unmistakable template text is listed here.
     */
    private const PLACEHOLDERS = [
        'your-api-key-here',
        'your_api_key_here',
        'your-api-key',
        'your_api_key',
        'yourapikey',
        'your-key-here',
        'api-key-here',
        'changeme',
        'change-me',
        'change_me',
        'placeholder',
        'todo',
        'tbd',
        'none',
        'null',
        'undefined',
    ];

    /**
     * Is this a real value the system may store and send to a provider?
     */
    public static function isUsable(?string $value): bool
    {
        return null !== $value && '' !== trim($value)
            && !self::isPlaceholder($value)
            && !self::isMasked($value);
    }

    public static function isPlaceholder(?string $value): bool
    {
        $value = strtolower(trim((string) $value));
        if ('' === $value) {
            return true;
        }

        if (in_array($value, self::PLACEHOLDERS, true)) {
            return true;
        }

        // <your-key>, ${OPENAI_API_KEY} — an unreplaced template or an
        // unexpanded shell variable that reached the config as literal text.
        if (1 === preg_match('/^(<.*>|\$\{.*\}|\$[a-z_][a-z0-9_]*)$/i', $value)) {
            return true;
        }

        // xxx, xxxxxxxx, sk-xxxxxxxx: filler, never a key.
        return 1 === preg_match('/^(sk-)?x{3,}$/', $value);
    }

    /**
     * The masked display value (or anything containing mask characters) coming
     * back from a client — saving it would destroy the stored key.
     */
    public static function isMasked(?string $value): bool
    {
        return null !== $value && str_contains($value, self::MASK_CHAR);
    }
}
