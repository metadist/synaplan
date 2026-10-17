<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileHelper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one answer to "which extension for this MIME type", for every path
 * that stores a file: every type it knows, with and without parameters, and
 * the caller's fallback for the rest.
 */
final class MimeExtensionMapTest extends TestCase
{
    /** @var array<string, string> */
    private const EXTENSIONS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/jpg' => 'jpg',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heic',
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
        'video/3gpp' => '3gp',
        'audio/mpeg' => 'mp3',
        'audio/mp3' => 'mp3',
        'audio/wav' => 'wav',
        'audio/ogg' => 'ogg',
        'audio/mp4' => 'm4a',
        'audio/amr' => 'amr',
        'audio/opus' => 'opus',
        'audio/webm' => 'webm',
        'application/pdf' => 'pdf',
        'application/msword' => 'doc',
        'application/vnd.ms-excel' => 'xls',
        'application/vnd.ms-powerpoint' => 'ppt',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
        'text/markdown' => 'md',
    ];

    public function testAVoiceNoteWithItsCodecGetsItsExtension(): void
    {
        self::assertSame('ogg', FileHelper::getExtensionFromMimeType('audio/ogg; codecs=opus'));
    }

    #[DataProvider('mimeTypes')]
    public function testEveryKnownTypeGetsItsExtension(string $mimeType, string $extension): void
    {
        self::assertSame($extension, FileHelper::getExtensionFromMimeType($mimeType));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function mimeTypes(): iterable
    {
        foreach (self::EXTENSIONS as $mimeType => $extension) {
            yield $mimeType => [$mimeType, $extension];
            yield $mimeType.'; codecs=x' => [$mimeType.'; codecs=x', $extension];
        }
    }

    public function testAnUnknownTypeGetsTheCallersFallback(): void
    {
        self::assertSame(
            ['bin', 'unknown', 'png'],
            [
                FileHelper::getExtensionFromMimeType('application/zip'),
                FileHelper::getExtensionFromMimeType('application/zip', 'unknown'),
                FileHelper::getExtensionFromMimeType('application/zip; charset=binary', 'png'),
            ],
        );
    }
}
