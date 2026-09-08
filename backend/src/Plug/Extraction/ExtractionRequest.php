<?php

declare(strict_types=1);

namespace App\Plug\Extraction;

/**
 * One file to extract. `family` is text / document / image / audio / video.
 */
final readonly class ExtractionRequest
{
    public function __construct(
        public string $absolutePath,
        public string $relativePath,
        public string $mime,
        public string $ext,
        public ?int $userId,
        public bool $describe,
        public string $family,
    ) {
    }

    public function withPath(string $absolutePath, string $ext, string $mime): self
    {
        return new self(
            $absolutePath,
            $this->relativePath,
            $mime,
            $ext,
            $this->userId,
            $this->describe,
            $this->family,
        );
    }

    /**
     * MIME-family used by {@see \App\Plug\PlugConfigService::extractionChain()}.
     * Mirrors FileProcessor's built-in routing so extra adapters land on the
     * same family the legacy strategies would have used.
     */
    public static function familyFrom(string $mime, string $ext): string
    {
        $plainText = [
            'text/plain',
            'text/markdown',
            'text/x-markdown',
            'text/csv',
            'text/html',
        ];
        if (\in_array($mime, $plainText, true)) {
            return 'text';
        }

        $images = [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
            'image/heic',
            'image/heif',
        ];
        if (\in_array($mime, $images, true)) {
            return 'image';
        }

        $video = ['mp4', 'mov', 'avi', 'mkv', 'mpeg', 'mpg'];
        if (\in_array($ext, $video, true)) {
            return 'video';
        }

        $audio = [
            'ogg', 'mp3', 'wav', 'm4a', 'opus', 'flac', 'webm', 'aac', 'wma', 'amr',
        ];
        if (\in_array($ext, $audio, true)) {
            return 'audio';
        }

        return 'document';
    }
}
