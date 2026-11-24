<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * Names a raster image by its leading bytes.
 *
 * ImageMagick picks a decoder from the content, not from the file name, so a
 * file called `photo.heic` that holds SVG, MVG or MSL text reaches those
 * coders, which read other files on the server. Every caller that hands
 * untrusted bytes to Imagick asks here first and decodes only what this class
 * names.
 */
final class ImageSignature
{
    public const JPEG = 'jpeg';
    public const PNG = 'png';
    public const GIF = 'gif';
    public const WEBP = 'webp';
    public const BMP = 'bmp';
    public const TIFF = 'tiff';
    public const HEIC = 'heic';
    public const AVIF = 'avif';

    /** Bytes of a file's start that are enough to name it. */
    public const HEAD_BYTES = 4096;

    private const HEVC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];
    private const AVIF_BRANDS = ['avif', 'avis'];
    private const HEIF_BRANDS = ['mif1', 'msf1', 'heif'];

    private const FTYP_HEADER_BYTES = 16;

    private function __construct()
    {
    }

    /**
     * The format (one of the constants above), or null when the bytes are not a raster image this class knows.
     */
    public static function of(string $bytes): ?string
    {
        return match (true) {
            str_starts_with($bytes, "\xFF\xD8\xFF") => self::JPEG,
            str_starts_with($bytes, "\x89PNG\r\n\x1A\n") => self::PNG,
            str_starts_with($bytes, 'GIF87a'), str_starts_with($bytes, 'GIF89a') => self::GIF,
            str_starts_with($bytes, 'RIFF') && 'WEBP' === substr($bytes, 8, 4) => self::WEBP,
            str_starts_with($bytes, "II*\x00"), str_starts_with($bytes, "MM\x00*") => self::TIFF,
            str_starts_with($bytes, 'BM') && self::looksLikeBmp($bytes) => self::BMP,
            default => self::isoMediaImage($bytes),
        };
    }

    /**
     * As {@see of()}, for a file on disk.
     */
    public static function ofFile(string $path): ?string
    {
        $head = @file_get_contents($path, false, null, 0, self::HEAD_BYTES);

        return false === $head ? null : self::of($head);
    }

    public static function isHeic(string $bytes): bool
    {
        return self::HEIC === self::of($bytes);
    }

    /**
     * A BMP file header: the reserved bytes are zero and the pixel offset lies past both headers.
     */
    private static function looksLikeBmp(string $bytes): bool
    {
        if (\strlen($bytes) < 14) {
            return false;
        }

        $header = unpack('Vreserved/Voffset', $bytes, 6);

        return false !== $header && 0 === $header['reserved'] && $header['offset'] >= 26;
    }

    /**
     * HEIC and AVIF are ISO media files: the first box is `ftyp` and lists the brands the file follows.
     */
    private static function isoMediaImage(string $bytes): ?string
    {
        if (\strlen($bytes) < self::FTYP_HEADER_BYTES || 'ftyp' !== substr($bytes, 4, 4)) {
            return null;
        }

        $size = unpack('N', $bytes)[1] ?? 0;
        if ($size < self::FTYP_HEADER_BYTES || $size > self::HEAD_BYTES) {
            return null;
        }

        $brands = [substr($bytes, 8, 4)];
        $end = min($size, \strlen($bytes));
        for ($offset = self::FTYP_HEADER_BYTES; $offset + 4 <= $end; $offset += 4) {
            $brands[] = substr($bytes, $offset, 4);
        }

        return match (true) {
            [] !== array_intersect($brands, self::HEVC_BRANDS) => self::HEIC,
            [] !== array_intersect($brands, self::AVIF_BRANDS) => self::AVIF,
            [] !== array_intersect($brands, self::HEIF_BRANDS) => self::HEIC,
            default => null,
        };
    }
}
