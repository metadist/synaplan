<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Service\Message\MessagePreProcessor;

/**
 * Single canonical resolver from a stored file type + name/path to a concrete
 * file extension and coarse media category.
 *
 * The generated-media pipelines store a GENERIC kind in BFILETYPE ("image",
 * "audio", "video", "document") rather than a concrete extension. That generic
 * kind is absent from the `*_EXTENSIONS` lists, so every consumer that compared
 * BFILETYPE against those lists had to re-implement the same "generic kind →
 * concrete extension" normalization. Doing it in several places is exactly how
 * #1236 was only half-fixed and regressed into #1300 (generated audio/video/
 * document attachments bypassed file_analysis routing).
 *
 * All routing/categorization consumers (MessageClassifier, FileAnalysisHandler)
 * MUST resolve through this one class so a new media kind or extension is
 * handled everywhere at once.
 */
final class FileTypeResolver
{
    /** The generic media kinds the generated-media pipelines store in BFILETYPE. */
    private const GENERIC_KINDS = ['image', 'audio', 'video', 'document'];

    /** Representative extension for a generic kind with no usable filename/path. */
    private const GENERIC_KIND_FALLBACK_EXTENSION = [
        'image' => 'png',
        'audio' => 'mp3',
        'video' => 'mp4',
        'document' => 'txt',
    ];

    /**
     * Whether a stored BFILETYPE value is a generic media kind rather than a
     * concrete file extension.
     */
    public static function isGenericFileKind(string $type): bool
    {
        return in_array(strtolower(trim($type)), self::GENERIC_KINDS, true);
    }

    /**
     * Resolve a concrete file extension (e.g. 'mp3', 'png', 'pdf').
     *
     * A concrete extension already stored in BFILETYPE is trusted as-is. A
     * generic kind ("audio"/…) or a missing type is recovered from the filename
     * first, then the path, and finally mapped onto a representative extension
     * for the kind so a typeless generated file is still categorized correctly.
     * Returns '' only when nothing at all identifies the file.
     */
    public static function resolveExtension(string $type, string $name = '', string $path = ''): string
    {
        $type = strtolower(trim($type));

        if ('' !== $type && !self::isGenericFileKind($type)) {
            return $type;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ('' === $extension) {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        }
        if ('' !== $extension) {
            return $extension;
        }

        return self::GENERIC_KIND_FALLBACK_EXTENSION[$type] ?? $type;
    }

    /**
     * Coarse media category for the resolved extension: 'image', 'audio',
     * 'video', 'document', or '' when it matches none of the known lists.
     */
    public static function resolveCategory(string $type, string $name = '', string $path = ''): string
    {
        $ext = self::resolveExtension($type, $name, $path);

        return match (true) {
            in_array($ext, MessagePreProcessor::IMAGE_EXTENSIONS, true) => 'image',
            in_array($ext, MessagePreProcessor::AUDIO_EXTENSIONS, true) => 'audio',
            in_array($ext, MessagePreProcessor::VIDEO_EXTENSIONS, true) => 'video',
            in_array($ext, MessagePreProcessor::DOCUMENT_EXTENSIONS, true) => 'document',
            default => '',
        };
    }
}
