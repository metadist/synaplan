<?php

declare(strict_types=1);

namespace App\Service\Media;

/**
 * Names for media files that are served without a credential.
 *
 * Meta's WhatsApp servers, anonymous widget visitors and image-to-video
 * providers fetch these files by URL and cannot be given a login, so the name
 * is the only secret. Every name built here carries 128 random bits.
 *
 * Releases before this class built the names from ids and the clock
 * (`tts_` + `uniqid()`, `{id}_{provider}_{unix time}`). Those names stay
 * recognisable through {@see self::legacyCreatedAt()} so that
 * {@see AnonymousMediaGate} can keep old links alive without accepting the
 * old shape for new files.
 */
final class PublicMediaName
{
    private const TOKEN_BYTES = 16;
    private const SPEECH_PREFIX = 'tts_';
    private const REMOTE_SOURCE_PREFIX = 'ai_i2vsrc_';
    private const FALLBACK_EXTENSION = 'bin';
    private const FALLBACK_LABEL = 'file';

    private const UNGUESSABLE_PATTERNS = [
        '/^tts_[0-9a-f]{32}\.[a-z0-9]+$/',
        '/^\d+_[a-z]+_[0-9a-f]{32}\.[a-z0-9]+$/',
        '/^ai_i2vsrc_\d+_[0-9a-f]{32}\.[a-z0-9]+$/',
    ];

    /** `tts_` + `uniqid()`: eight hex digits of unix time, five of microseconds. */
    private const LEGACY_SPEECH_PATTERN = '/^tts_(?:test_)?([0-9a-f]{8})[0-9a-f]{5}\.[a-z0-9]+$/';
    /** `{id}_{provider}_{unix time}`, published copies with three more digits. */
    private const LEGACY_GENERATED_PATTERN = '/^\d+_[a-z]+_(\d{10})(?:\d{3})?\.[a-z0-9]+$/i';
    private const LEGACY_REMOTE_SOURCE_PATTERN = '/^ai_i2vsrc_\d+_(\d{10})_[0-9a-f]{12}\.[a-z0-9]+$/';

    /**
     * A synthesized speech file: `tts_{token}.{ext}`.
     */
    public static function speech(string $extension): string
    {
        return self::SPEECH_PREFIX.self::token().'.'.self::extension($extension);
    }

    /**
     * A generated or published file: `{id}_{label}_{token}.{ext}`. The id and
     * the label only help an operator read a directory listing.
     */
    public static function generated(int $id, string $label, string $extension): string
    {
        $letters = preg_replace('/[^a-z]/', '', strtolower($label));
        $label = is_string($letters) && '' !== $letters ? $letters : self::FALLBACK_LABEL;

        return sprintf('%d_%s_%s.%s', $id, $label, self::token(), self::extension($extension));
    }

    /**
     * An input image a remote provider fetches: `ai_i2vsrc_{userId}_{token}.{ext}`.
     */
    public static function remoteSource(int $userId, string $extension): string
    {
        return sprintf('%s%d_%s.%s', self::REMOTE_SOURCE_PREFIX, $userId, self::token(), self::extension($extension));
    }

    public static function isUnguessable(string $filename): bool
    {
        foreach (self::UNGUESSABLE_PATTERNS as $pattern) {
            if (1 === preg_match($pattern, $filename)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The unix time an old-style name was built at, read from the name itself.
     * Null when the name is not one an earlier release produced.
     */
    public static function legacyCreatedAt(string $filename): ?int
    {
        if (1 === preg_match(self::LEGACY_SPEECH_PATTERN, $filename, $match)) {
            return (int) hexdec($match[1]);
        }

        foreach ([self::LEGACY_GENERATED_PATTERN, self::LEGACY_REMOTE_SOURCE_PATTERN] as $pattern) {
            if (1 === preg_match($pattern, $filename, $match)) {
                return (int) $match[1];
            }
        }

        return null;
    }

    private static function token(): string
    {
        return bin2hex(random_bytes(self::TOKEN_BYTES));
    }

    private static function extension(string $extension): string
    {
        $extension = strtolower(ltrim($extension, '.'));

        return 1 === preg_match('/^[a-z0-9]+$/', $extension) ? $extension : self::FALLBACK_EXTENSION;
    }
}
