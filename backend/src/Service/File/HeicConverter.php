<?php

declare(strict_types=1);

namespace App\Service\File;

use Psr\Log\LoggerInterface;

/**
 * Converts Apple HEIC/HEIF photos to JPEG.
 *
 * iPhones store photos as HEIC by default. Neither browsers nor the hosted
 * vision providers (OpenAI, Anthropic, Google) accept HEIC, so every HEIC
 * upload is transcoded to a widely-supported JPEG at ingest time. EXIF
 * orientation is baked into the pixels before the metadata is stripped, so the
 * resulting JPEG renders upright everywhere.
 *
 * Decoding relies on ImageMagick's built-in `heic` delegate (libheif), exposed
 * through the PHP `imagick` extension shipped in the base image.
 */
final readonly class HeicConverter
{
    /**
     * Extensions handled by this converter (lowercase, no leading dot).
     */
    private const HEIC_EXTENSIONS = ['heic', 'heif'];

    /**
     * MIME types reported for HEIC/HEIF containers.
     */
    private const HEIC_MIMES = ['image/heic', 'image/heif', 'image/heic-sequence', 'image/heif-sequence'];

    public function __construct(
        private LoggerInterface $logger,
        private int $quality = 85,
    ) {
    }

    /**
     * Whether HEIC transcoding is available in this runtime.
     */
    public function isSupported(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    /**
     * Decide whether a file should be transcoded based on its extension/MIME.
     */
    public function isHeic(string $extension, ?string $mime = null): bool
    {
        if (in_array(strtolower($extension), self::HEIC_EXTENSIONS, true)) {
            return true;
        }

        return null !== $mime && in_array(strtolower($mime), self::HEIC_MIMES, true);
    }

    /**
     * Transcode raw HEIC bytes to JPEG bytes.
     *
     * @return string|null JPEG bytes, or null when conversion is unavailable or fails
     */
    public function convertBlobToJpeg(string $heicBytes): ?string
    {
        if (!$this->isSupported()) {
            $this->logger->warning('HeicConverter: imagick extension unavailable, cannot convert HEIC');

            return null;
        }

        try {
            $imagick = new \Imagick();
            $imagick->readImageBlob($heicBytes);

            // A HEIC container may hold several images (depth map, thumbnails,
            // burst frames). Keep only the primary frame for a single JPEG.
            $imagick->setIteratorIndex(0);

            // Bake EXIF orientation into the pixels, then drop all metadata so
            // the JPEG is small and never double-rotates in a browser.
            $imagick->autoOrient();
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($this->quality);
            $imagick->stripImage();

            $jpeg = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            if ('' === $jpeg) {
                $this->logger->error('HeicConverter: conversion produced empty JPEG');

                return null;
            }

            return $jpeg;
        } catch (\Throwable $e) {
            $this->logger->error('HeicConverter: HEIC to JPEG conversion failed', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Transcode a HEIC file on disk to a JPEG file on disk.
     *
     * @return bool true on success
     */
    public function convertFileToJpeg(string $sourcePath, string $destinationPath): bool
    {
        $bytes = @file_get_contents($sourcePath);
        if (false === $bytes) {
            $this->logger->error('HeicConverter: could not read source file', ['path' => $sourcePath]);

            return false;
        }

        $jpeg = $this->convertBlobToJpeg($bytes);
        if (null === $jpeg) {
            return false;
        }

        if (false === FileHelper::writeFile($destinationPath, $jpeg)) {
            $this->logger->error('HeicConverter: could not write converted file', ['path' => $destinationPath]);

            return false;
        }

        return true;
    }
}
