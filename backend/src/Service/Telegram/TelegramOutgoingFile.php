<?php

declare(strict_types=1);

namespace App\Service\Telegram;

use App\Service\Media\OutboundChannelMedia;

/**
 * One generated file for the reply: an upload-dir path and what it is.
 */
final readonly class TelegramOutgoingFile
{
    public const IMAGE = 'image';
    public const VIDEO = 'video';
    public const AUDIO = 'audio';
    public const DOCUMENT = 'document';

    public function __construct(
        public string $path,
        public string $type,
        public ?string $name = null,
    ) {
    }

    /**
     * Every file a processor response carries: the single `file`, the
     * multitask `files` list, and the office `generated_file`.
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<self>
     */
    public static function fromMetadata(array $metadata): array
    {
        $files = [];
        $seen = [];
        $candidates = [];
        if (is_array($metadata['file'] ?? null)) {
            $candidates[] = $metadata['file'];
        }
        foreach (is_array($metadata['files'] ?? null) ? $metadata['files'] : [] as $entry) {
            if (is_array($entry)) {
                $candidates[] = $entry;
            }
        }
        if (is_array($metadata['generated_file'] ?? null)) {
            $candidates[] = ['type' => self::DOCUMENT] + $metadata['generated_file'];
        }

        foreach ($candidates as $candidate) {
            $path = $candidate['path'] ?? null;
            if (!is_string($path) || '' === trim($path)) {
                continue;
            }
            $key = OutboundChannelMedia::relativeUploadPath($path);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $name = $candidate['filename'] ?? null;
            $files[] = new self($path, self::normalizeType($candidate['type'] ?? null, $path), is_string($name) ? $name : null);
        }

        return $files;
    }

    public static function forPath(string $path, ?string $type): self
    {
        return new self($path, self::normalizeType($type, $path));
    }

    public function relativePath(): string
    {
        return OutboundChannelMedia::relativeUploadPath($this->path);
    }

    public function displayName(): string
    {
        if (null !== $this->name && '' !== trim($this->name)) {
            return basename(str_replace('\\', '/', $this->name));
        }

        return basename($this->relativePath());
    }

    private static function normalizeType(mixed $type, string $path): string
    {
        $type = is_string($type) ? strtolower($type) : '';
        if (in_array($type, [self::IMAGE, self::VIDEO, self::AUDIO, self::DOCUMENT], true)) {
            return $type;
        }
        $extension = strtolower(pathinfo($path, \PATHINFO_EXTENSION));

        return match (true) {
            in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true) => self::IMAGE,
            in_array($extension, ['mp4', 'mov', 'webm', 'mkv'], true) => self::VIDEO,
            in_array($extension, ['mp3', 'wav', 'ogg', 'm4a', 'opus', 'flac'], true) => self::AUDIO,
            default => self::DOCUMENT,
        };
    }
}
