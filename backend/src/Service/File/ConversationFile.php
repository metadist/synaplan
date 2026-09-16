<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * One file that belongs to a conversation, described the way every consumer
 * needs it: a stable reference the model can name, an on-disk path a provider
 * can read, and enough metadata to decide whether it is the file the user just
 * talked about.
 *
 * Produced exclusively by {@see ConversationFileCatalog} so the offered
 * references and the accepted ones can never drift apart.
 */
final readonly class ConversationFile
{
    public const ORIGIN_ATTACHED = 'attached';
    public const ORIGIN_GENERATED = 'generated';
    public const ORIGIN_UPLOADED = 'uploaded';

    public const CATEGORY_IMAGE = 'image';
    public const CATEGORY_DOCUMENT = 'document';
    public const CATEGORY_AUDIO = 'audio';
    public const CATEGORY_VIDEO = 'video';
    public const CATEGORY_OTHER = 'other';

    private const EXTENSION_CATEGORIES = [
        'gif' => self::CATEGORY_IMAGE,
        'jpeg' => self::CATEGORY_IMAGE,
        'jpg' => self::CATEGORY_IMAGE,
        'png' => self::CATEGORY_IMAGE,
        'webp' => self::CATEGORY_IMAGE,
        'csv' => self::CATEGORY_DOCUMENT,
        'doc' => self::CATEGORY_DOCUMENT,
        'docx' => self::CATEGORY_DOCUMENT,
        'md' => self::CATEGORY_DOCUMENT,
        'odp' => self::CATEGORY_DOCUMENT,
        'ods' => self::CATEGORY_DOCUMENT,
        'odt' => self::CATEGORY_DOCUMENT,
        'pdf' => self::CATEGORY_DOCUMENT,
        'ppt' => self::CATEGORY_DOCUMENT,
        'pptx' => self::CATEGORY_DOCUMENT,
        'rtf' => self::CATEGORY_DOCUMENT,
        'txt' => self::CATEGORY_DOCUMENT,
        'xls' => self::CATEGORY_DOCUMENT,
        'xlsx' => self::CATEGORY_DOCUMENT,
        'aac' => self::CATEGORY_AUDIO,
        'flac' => self::CATEGORY_AUDIO,
        'm4a' => self::CATEGORY_AUDIO,
        'mp3' => self::CATEGORY_AUDIO,
        'oga' => self::CATEGORY_AUDIO,
        'ogg' => self::CATEGORY_AUDIO,
        'opus' => self::CATEGORY_AUDIO,
        'wav' => self::CATEGORY_AUDIO,
        'avi' => self::CATEGORY_VIDEO,
        'mkv' => self::CATEGORY_VIDEO,
        'mov' => self::CATEGORY_VIDEO,
        'mp4' => self::CATEGORY_VIDEO,
        'm4v' => self::CATEGORY_VIDEO,
        'webm' => self::CATEGORY_VIDEO,
    ];

    /**
     * @param string   $reference    marker payload, e.g. `file:42` or `attached:1`
     * @param string   $displayName  single-line safe name shown to the model
     * @param string   $category     one of the CATEGORY_* constants
     * @param string   $origin       one of the ORIGIN_* constants
     * @param string   $absolutePath validated path inside the upload dir
     * @param string   $relativePath upload-dir-relative path as stored in BFILES
     * @param int|null $fileId       BFILES.BID, null for legacy path-only entries
     * @param int|null $messageId    message the file belongs to
     * @param string   $direction    `IN` / `OUT` of that message, empty when unknown
     */
    public function __construct(
        public string $reference,
        public string $displayName,
        public string $category,
        public string $origin,
        public string $absolutePath,
        public string $relativePath,
        public ?int $fileId = null,
        public ?int $messageId = null,
        public string $direction = '',
    ) {
    }

    /**
     * Category of a stored path. Static so prompt-building code can label a
     * file without pulling in the catalog service.
     */
    public static function categoryForPath(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::EXTENSION_CATEGORIES[$extension] ?? self::CATEGORY_OTHER;
    }

    public function isImage(): bool
    {
        return self::CATEGORY_IMAGE === $this->category;
    }

    public function isGenerated(): bool
    {
        return self::ORIGIN_GENERATED === $this->origin;
    }
}
