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
     * The bytes are staged to a temporary `.heic` file and decoded via a file
     * path with an explicit `heic:` coder hint. Imagick's in-memory
     * `readImageBlob()` relies on magic-byte sniffing, which fails ("Unable to
     * read image blob") on real iPhone HEICs (tiled HEVC) even though the
     * libheif/libde265 delegates are installed — reading from a path is
     * reliable.
     *
     * @param string|null $error Out-param set to a technical failure reason
     *                           (for admin diagnostics) when conversion fails
     *
     * @return string|null JPEG bytes, or null when conversion is unavailable or fails
     */
    public function convertBlobToJpeg(string $heicBytes, ?string &$error = null): ?string
    {
        $error = null;

        if (!$this->isSupported()) {
            $error = 'imagick extension is not loaded';
            $this->logger->warning('HeicConverter: imagick extension unavailable, cannot convert HEIC');

            return null;
        }

        $tempHeic = tempnam(sys_get_temp_dir(), 'heic_').'.heic';
        if (false === @file_put_contents($tempHeic, $heicBytes)) {
            $error = 'could not stage HEIC bytes to a temporary file';
            $this->logger->error('HeicConverter: '.$error);
            @unlink($tempHeic);

            return null;
        }

        try {
            $imagick = $this->readHeic($tempHeic);

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
                $error = 'decoder produced an empty image';
                $this->logger->error('HeicConverter: conversion produced empty JPEG');

                return null;
            }

            return $jpeg;
        } catch (\Throwable $e) {
            // "Failed to read the file" here typically means the installed
            // libheif is too old for the file (e.g. iOS HDR/`tmap` HEICs need
            // libheif >= 1.18).
            $error = $e->getMessage();
            $this->logger->error('HeicConverter: HEIC to JPEG conversion failed', [
                'error' => $e->getMessage(),
                'bytes' => strlen($heicBytes),
            ]);

            return null;
        } finally {
            @unlink($tempHeic);
        }
    }

    /**
     * Transcode a HEIC file on disk to a JPEG file on disk.
     *
     * @return bool true on success
     */
    public function convertFileToJpeg(string $sourcePath, string $destinationPath): bool
    {
        if (!$this->isSupported()) {
            $this->logger->warning('HeicConverter: imagick extension unavailable, cannot convert HEIC');

            return false;
        }

        try {
            $imagick = $this->readHeic($sourcePath);
            $imagick->setIteratorIndex(0);
            $imagick->autoOrient();
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($this->quality);
            $imagick->stripImage();
            $jpeg = $imagick->getImageBlob();
            $imagick->clear();
            $imagick->destroy();

            if ('' === $jpeg) {
                $this->logger->error('HeicConverter: conversion produced empty JPEG', ['path' => $sourcePath]);

                return false;
            }

            if (false === FileHelper::writeFile($destinationPath, $jpeg)) {
                $this->logger->error('HeicConverter: could not write converted file', ['path' => $destinationPath]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('HeicConverter: HEIC to JPEG conversion failed', [
                'path' => $sourcePath,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Read a HEIC file into an Imagick instance, preferring the explicit
     * `heic:` coder and falling back to plain path-based detection.
     */
    private function readHeic(string $path): \Imagick
    {
        try {
            $imagick = new \Imagick();
            $imagick->readImage('heic:'.$path);

            return $imagick;
        } catch (\Throwable) {
            // Fall back to extension/magic-based detection (handles .heif and
            // any build where the explicit coder prefix is unavailable).
            $imagick = new \Imagick();
            $imagick->readImage($path);

            return $imagick;
        }
    }
}
