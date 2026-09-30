<?php

declare(strict_types=1);

namespace App\Service\Message;

use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Turns internal generated-media markers into text for users or models.
 *
 * Stored BTEXT for generated image / video / audio / files is a marker
 * ({@see self::MARKER_IMAGE}, …). The rewritten prompt lives only in message
 * meta {@code media_prompt}. Channels, email, MCP and exports use
 * {@see self::forUser()}; model-facing history uses {@see self::forModel()}.
 */
final readonly class GeneratedMediaTextRenderer
{
    public const MARKER_IMAGE = '__IMAGE_GENERATED__';
    public const MARKER_VIDEO = '__VIDEO_GENERATED__';
    public const MARKER_AUDIO = '__AUDIO_GENERATED__';
    public const MARKER_FILE_PREFIX = '__FILE_GENERATED__:';
    public const MARKER_FILE_FAILED = '__FILE_GENERATION_FAILED__';

    private const DOMAIN = 'generated_media';

    /** @var list<string> */
    private const SUPPORTED_LOCALES = ['de', 'en', 'es', 'fr', 'tr'];

    public function __construct(
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * True when the whole text (or its first line) is a generated-media marker.
     */
    public function isMediaMarker(?string $text): bool
    {
        return null !== $this->parseMarker((string) $text);
    }

    /**
     * Localized sentence for channels, email, MCP, shared page, exports.
     *
     * Locale preference: message language ({@code $messageLang} / BLANG), then
     * {@code $fallbackLocale} (user locale), then English.
     */
    public function forUser(?string $text, ?string $messageLang = null, ?string $fallbackLocale = null): string
    {
        $text = (string) $text;
        if ('' === $text) {
            return '';
        }

        $parsed = $this->parseMarker($text);
        if (null === $parsed) {
            return $text;
        }

        [$kind, $filename, $suffix] = $parsed;
        $locale = $this->resolveLocale($messageLang, $fallbackLocale);
        $sentence = $this->userSentence($kind, $filename, $locale);

        return '' === $suffix ? $sentence : $sentence.$suffix;
    }

    /**
     * Neutral English description for model history, titles, memory extraction.
     * Never contains {@code __} or the legacy {@code Generated } prefix.
     */
    public function forModel(?string $text): string
    {
        return self::renderModel((string) $text);
    }

    /**
     * Same as {@see self::forModel()} without needing the translator — usable
     * from unit tests that construct ChatHandler without the full container.
     */
    public static function renderModel(string $text): string
    {
        if ('' === $text) {
            return '';
        }

        $parsed = self::parseMarkerStatic($text);
        if (null === $parsed) {
            return $text;
        }

        [$kind, $filename, $suffix] = $parsed;
        $sentence = match ($kind) {
            'image' => '(I generated an image and provided it to the user.)',
            'video' => '(I generated a video and provided it to the user.)',
            'audio' => '(I generated audio and provided it to the user.)',
            'file' => sprintf('(I generated the file "%s" and provided it to the user as a download.)', $filename ?? 'file'),
            'file_failed' => '(The requested file could not be generated.)',
            default => $text,
        };

        return '' === $suffix ? $sentence : $sentence.$suffix;
    }

    /**
     * Marker string to store in BTEXT for a successful media generation.
     */
    public static function storageMarker(string $mediaType): string
    {
        return match (strtolower($mediaType)) {
            'image' => self::MARKER_IMAGE,
            'video' => self::MARKER_VIDEO,
            'audio' => self::MARKER_AUDIO,
            default => self::MARKER_AUDIO,
        };
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}|null kind, filename, trailing suffix
     */
    private function parseMarker(string $text): ?array
    {
        return self::parseMarkerStatic($text);
    }

    /**
     * @return array{0: string, 1: ?string, 2: string}|null
     */
    private static function parseMarkerStatic(string $text): ?array
    {
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return null;
        }

        // Exact marker, or marker as the first line (folder-delivery note after).
        $firstLine = $trimmed;
        $suffix = '';
        if (str_contains($trimmed, "\n")) {
            $parts = explode("\n", $trimmed, 2);
            $firstLine = trim($parts[0]);
            $suffix = "\n".$parts[1];
        }

        if (self::MARKER_IMAGE === $firstLine) {
            return ['image', null, $suffix];
        }
        if (self::MARKER_VIDEO === $firstLine) {
            return ['video', null, $suffix];
        }
        if (self::MARKER_AUDIO === $firstLine) {
            return ['audio', null, $suffix];
        }
        if (self::MARKER_FILE_FAILED === $firstLine) {
            return ['file_failed', null, $suffix];
        }
        if (str_starts_with($firstLine, self::MARKER_FILE_PREFIX)) {
            $filename = trim(substr($firstLine, strlen(self::MARKER_FILE_PREFIX)));

            return ['file', '' !== $filename ? $filename : 'file', $suffix];
        }

        // Legacy stored prose (pre-migration). Never echo the rewritten prompt.
        if (preg_match('/^Generated image:\s*/i', $firstLine)) {
            return ['image', null, $suffix];
        }
        if (preg_match('/^Generated video:\s*/i', $firstLine)) {
            return ['video', null, $suffix];
        }
        if (preg_match('/^Generated audio:\s*/i', $firstLine)) {
            return ['audio', null, $suffix];
        }

        return null;
    }

    private function userSentence(string $kind, ?string $filename, string $locale): string
    {
        $key = match ($kind) {
            'image' => 'image_generated',
            'video' => 'video_generated',
            'audio' => 'audio_generated',
            'file' => 'file_generated',
            'file_failed' => 'file_generation_failed',
            default => 'image_generated',
        };

        $params = [];
        if ('file' === $kind) {
            $params['filename'] = $filename ?? 'file';
        }

        return $this->translator->trans($key, $params, self::DOMAIN, $locale);
    }

    private function resolveLocale(?string $messageLang, ?string $fallbackLocale): string
    {
        foreach ([$messageLang, $fallbackLocale, 'en'] as $candidate) {
            if (null === $candidate || '' === $candidate) {
                continue;
            }
            $normalized = strtolower(substr($candidate, 0, 2));
            if (in_array($normalized, self::SUPPORTED_LOCALES, true)) {
                return $normalized;
            }
        }

        return 'en';
    }
}
