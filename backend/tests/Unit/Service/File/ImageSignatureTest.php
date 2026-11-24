<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\File;

use App\Service\File\ImageSignature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImageSignatureTest extends TestCase
{
    #[DataProvider('rasterImages')]
    public function testItNamesARasterImageByItsLeadingBytes(string $bytes, string $expected): void
    {
        self::assertSame($expected, ImageSignature::of($bytes));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rasterImages(): iterable
    {
        yield 'jpeg' => ["\xFF\xD8\xFF\xE0\x00\x10JFIF", ImageSignature::JPEG];
        yield 'png' => ["\x89PNG\r\n\x1A\n\x00\x00\x00\rIHDR", ImageSignature::PNG];
        yield 'gif' => ['GIF89a'."\x01\x00\x01\x00", ImageSignature::GIF];
        yield 'webp' => ['RIFF'."\x24\x00\x00\x00".'WEBPVP8 ', ImageSignature::WEBP];
        yield 'tiff' => ["II*\x00\x08\x00\x00\x00", ImageSignature::TIFF];
        yield 'bmp' => ['BM'.pack('V', 70)."\x00\x00\x00\x00".pack('V', 54).str_repeat("\x00", 16), ImageSignature::BMP];
        yield 'iPhone heic' => [self::ftyp('heic', ['mif1', 'heic']), ImageSignature::HEIC];
        yield 'heif with a generic major brand' => [self::ftyp('mif1', ['mif1', 'heic']), ImageSignature::HEIC];
        yield 'heif without a codec brand' => [self::ftyp('mif1', ['mif1']), ImageSignature::HEIC];
        yield 'avif' => [self::ftyp('avif', ['mif1', 'miaf']), ImageSignature::AVIF];
    }

    #[DataProvider('otherContent')]
    public function testItDoesNotNameAnythingElse(string $bytes): void
    {
        self::assertNull(ImageSignature::of($bytes));
        self::assertFalse(ImageSignature::isHeic($bytes));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function otherContent(): iterable
    {
        yield 'empty' => [''];
        yield 'svg' => ['<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg"><image href="/etc/hostname"/></svg>'];
        yield 'svg without a prolog' => ['<svg xmlns="http://www.w3.org/2000/svg"/>'];
        yield 'mvg' => ["push graphic-context\nviewbox 0 0 10 10\nimage over 0,0 0,0 'label:x'\npop graphic-context"];
        yield 'msl' => ['<?xml version="1.0" encoding="UTF-8"?><image><read filename="/etc/hostname"/></image>'];
        yield 'text that starts like a bmp' => ['BMP files are images, this is prose.'];
        yield 'mp4 video' => [self::ftyp('isom', ['isom', 'mp42'])];
        yield 'ftyp box that claims to be huge' => ["\x7F\xFF\xFF\xFF".'ftypheic'."\x00\x00\x00\x00".'heic'];
        yield 'ftyp box cut short' => ["\x00\x00\x00\x18".'ftyphe'];
        yield 'svg after a heic-looking comment' => ['<!-- ftypheic --><svg xmlns="http://www.w3.org/2000/svg"/>'];
    }

    public function testAFileIsNamedFromItsFirstBytes(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'imgsig_');

        try {
            file_put_contents($path, self::ftyp('heic', ['mif1', 'heic']).str_repeat('x', 10_000));
            self::assertSame(ImageSignature::HEIC, ImageSignature::ofFile($path));

            file_put_contents($path, '<svg xmlns="http://www.w3.org/2000/svg"/>');
            self::assertNull(ImageSignature::ofFile($path));
        } finally {
            @unlink($path);
        }

        self::assertNull(ImageSignature::ofFile($path));
    }

    /**
     * @param list<string> $compatible
     */
    private static function ftyp(string $major, array $compatible): string
    {
        $body = $major."\x00\x00\x00\x00".implode('', $compatible);

        return pack('N', 8 + \strlen($body)).'ftyp'.$body."\x00\x00\x00\x08mdat";
    }
}
