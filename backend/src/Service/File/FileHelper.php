<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * Shared file utility methods.
 */
final class FileHelper
{
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
