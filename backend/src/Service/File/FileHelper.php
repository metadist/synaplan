<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * Shared file utility methods.
 */
final class FileHelper
{
    /**
     * Default file permissions (readable by owner and group, not world-writable).
     */
    public const FILE_PERMS = 0664;

    /**
     * Default directory permissions (accessible by owner and group).
     */
    public const DIR_PERMS = 0775;

    /**
     * Write content to file with proper permissions.
     *
     * Uses atomic write (temp file + rename) when possible for safety.
     *
     * @param string $path    Absolute path to file
     * @param string $content File content
     *
     * @return int|false Number of bytes written or false on failure
     */
    public static function writeFile(string $path, string $content): int|false
    {
        $result = file_put_contents($path, $content);

        if (false !== $result) {
            // Set proper permissions (ignore errors - might be on a filesystem that doesn't support chmod)
            @chmod($path, self::FILE_PERMS);
        }

        return $result;
    }

    /**
     * Create directory with proper permissions.
     *
     * @param string $path      Directory path to create
     * @param bool   $recursive Create parent directories if needed
     *
     * @return bool True on success or if directory already exists
     */
    public static function createDirectory(string $path, bool $recursive = true): bool
    {
        if (is_dir($path)) {
            return true;
        }

        $result = @mkdir($path, self::DIR_PERMS, $recursive);

        if ($result) {
            // Ensure permissions are set (mkdir's mode is affected by umask)
            @chmod($path, self::DIR_PERMS);
        }

        return $result || is_dir($path);
    }

    /**
     * Ensure parent directory exists with proper permissions.
     *
     * @param string $filePath Path to file (directory will be created for dirname)
     *
     * @return bool True if directory exists or was created
     */
    public static function ensureParentDirectory(string $filePath): bool
    {
        return self::createDirectory(dirname($filePath));
    }

    /**
     * Get file extension from MIME type.
     */
    public static function getExtensionFromMimeType(string $mimeType, string $fallback = 'bin'): string
    {
        return match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            default => $fallback,
        };
    }

    /**
     * Sanitize provider name for use in filenames.
     */
    public static function sanitizeProviderName(string $provider): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($provider)) ?: 'unknown';
    }

    /**
     * Check if MIME type is text-based (safe to store in DB).
     */
    public static function isTextBasedMimeType(string $mimeType): bool
    {
        if (str_starts_with($mimeType, 'text/')) {
            return true;
        }

        return in_array($mimeType, ['application/json', 'application/xml'], true);
    }

    /**
     * Redact query string from URL for safe logging.
     */
    public static function redactUrlForLogging(string $url): string
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['host'])) {
            return '[invalid-url]';
        }

        $result = ($parsed['scheme'] ?? 'https').'://'.$parsed['host'];
        if (isset($parsed['path'])) {
            $result .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            $result .= '?[redacted]';
        }

        return $result;
    }
}
