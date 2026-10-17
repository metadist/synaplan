<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\FileHelper;
use App\Service\WhatsApp\WhatsAppService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two answers to "which extension for this MIME type", side by side:
 * FileHelper (generated media, data URLs; fallback "bin") and WhatsApp's own
 * copy (media downloads; fallback "unknown", then its allow-list). Every key
 * either map knows, with and without parameters.
 */
final class MimeExtensionMapTest extends TestCase
{
    /** @var array<string, array{string, string}> MIME type => [FileHelper, WhatsApp] */
    private const TODAY = [
        'image/png' => ['png', 'png'],
        'image/jpeg' => ['jpg', 'jpg'],
        'image/jpg' => ['jpg', 'unknown'],
        'image/gif' => ['gif', 'gif'],
        'image/webp' => ['webp', 'webp'],
        'image/heic' => ['heic', 'unknown'],
        'image/heif' => ['heic', 'unknown'],
        'video/mp4' => ['mp4', 'mp4'],
        'video/webm' => ['webm', 'webm'],
        'video/3gpp' => ['bin', '3gp'],
        'audio/mpeg' => ['mp3', 'mp3'],
        'audio/mp3' => ['mp3', 'unknown'],
        'audio/wav' => ['wav', 'wav'],
        'audio/ogg' => ['bin', 'ogg'],
        'audio/mp4' => ['bin', 'm4a'],
        'audio/amr' => ['bin', 'amr'],
        'audio/opus' => ['bin', 'opus'],
        'audio/webm' => ['bin', 'webm'],
        'application/pdf' => ['bin', 'pdf'],
        'application/msword' => ['bin', 'doc'],
        'application/vnd.ms-excel' => ['bin', 'xls'],
        'application/vnd.ms-powerpoint' => ['bin', 'ppt'],
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['bin', 'docx'],
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['bin', 'xlsx'],
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['bin', 'pptx'],
        'text/plain' => ['bin', 'txt'],
        'text/csv' => ['bin', 'csv'],
        'text/markdown' => ['bin', 'md'],
        'application/zip' => ['bin', 'unknown'],
    ];

    #[DataProvider('mimeTypes')]
    public function testBothMapsAnswerAsTheyDoToday(string $mimeType, string $fileHelper, string $whatsApp): void
    {
        self::assertSame([$fileHelper, $whatsApp], [FileHelper::getExtensionFromMimeType($mimeType), $this->whatsApp($mimeType)]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function mimeTypes(): iterable
    {
        foreach (self::TODAY as $mimeType => [$fileHelper, $whatsApp]) {
            yield $mimeType => [$mimeType, $fileHelper, $whatsApp];
            // FileHelper matches the whole string, so a parameter misses its key.
            yield $mimeType.'; codecs=x' => [$mimeType.'; codecs=x', 'bin', $whatsApp];
        }
    }

    private function whatsApp(string $mimeType): string
    {
        $service = (new \ReflectionClass(WhatsAppService::class))->newInstanceWithoutConstructor();

        return (string) (new \ReflectionMethod(WhatsAppService::class, 'getExtensionFromMimeType'))->invoke($service, $mimeType);
    }
}
