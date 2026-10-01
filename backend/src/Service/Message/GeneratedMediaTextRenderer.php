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

        $locale = $this->resolveLocale($messageLang, $fallbackLocale);

        return self::rewriteLines(
            $text,
            fn (string $kind, ?string $filename): string => $this->userSentence($kind, $filename, $locale),
        );
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

        return self::rewriteLines($text, [self::class, 'modelSentence']);
    }

    private static function modelSentence(string $kind, ?string $filename): string
    {
        return match ($kind) {
            'image' => '(I generated an image and provided it to the user.)',
            'video' => '(I generated a video and provided it to the user.)',
            'audio' => '(I generated audio and provided it to the user.)',
            'file' => sprintf('(I generated the file "%s" and provided it to the user as a download.)', $filename ?? 'file'),
            'file_failed' => '(The requested file could not be generated.)',
            default => '',
        };
    }

    /**
     * Replace every marker line. Prose around it stays, including a folder
     * note on the following lines. Text with no marker is returned unchanged.
     *
     * @param callable(string, ?string): string $sentence
     */
    private static function rewriteLines(string $text, callable $sentence): string
    {
        $lines = explode("\n", $text);
        $changed = false;
        foreach ($lines as $index => $line) {
            $parsed = self::parseMarkerLine($line);
            if (null === $parsed) {
                continue;
            }
            $lines[$index] = $sentence($parsed[0], $parsed[1]);
            $changed = true;
        }

        return $changed ? implode("\n", $lines) : $text;
    }

    /**
     * @return array{0: string, 1: ?string}|null
     */
    private static function parseMarkerLine(string $line): ?array
    {
        $trimmed = trim($line);
        if ('' === $trimmed) {
            return null;
        }
        if (self::MARKER_IMAGE === $trimmed) {
            return ['image', null];
        }
        if (self::MARKER_VIDEO === $trimmed) {
            return ['video', null];
        }
        if (self::MARKER_AUDIO === $trimmed) {
            return ['audio', null];
        }
        if (self::MARKER_FILE_FAILED === $trimmed) {
            return ['file_failed', null];
        }
        if (str_starts_with($trimmed, self::MARKER_FILE_PREFIX)) {
            $filename = trim(substr($trimmed, strlen(self::MARKER_FILE_PREFIX)));

            return ['file', '' !== $filename ? $filename : 'file'];
        }
        if (preg_match('/^Generated image:\s*/i', $trimmed)) {
            return ['image', null];
        }
        if (preg_match('/^Generated video:\s*/i', $trimmed)) {
            return ['video', null];
        }
        if (preg_match('/^Generated audio:\s*/i', $trimmed)) {
            return ['audio', null];
        }

        return null;
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
        $line = self::parseMarkerLine($text);
        if (null === $line) {
            $first = trim(explode("\n", $text, 2)[0]);
            $line = self::parseMarkerLine($first);
            if (null === $line) {
                return null;
            }
        }

        return [$line[0], $line[1], ''];
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
