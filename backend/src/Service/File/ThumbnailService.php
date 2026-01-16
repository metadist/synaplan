<?php

declare(strict_types=1);

namespace App\Service\File;

use Psr\Log\LoggerInterface;

/**
 * Thumbnail generation service using ffmpeg.
 *
 * Generates thumbnails for video files and manages their lifecycle.
 * Thumbnail naming convention: {videoBasename}_thumb.jpg
 */
final class ThumbnailService
{
    private const THUMBNAIL_SUFFIX = '_thumb.jpg';
    private const DEFAULT_TIMESTAMP = '00:00:01'; // 1 second into video
    private const DEFAULT_QUALITY = 2; // ffmpeg quality (1-31, lower is better)

    public function __construct(
        private string $uploadDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate thumbnail for a video file.
     *
     * @param string      $videoRelativePath relative path to video from uploadDir
     * @param string|null $timestamp         timestamp to capture (default: 1 second)
     *
     * @return string|null relative path to thumbnail or null on failure
     */
    public function generateThumbnail(string $videoRelativePath, ?string $timestamp = null): ?string
    {
        $videoAbsolutePath = $this->uploadDir.'/'.ltrim($videoRelativePath, '/');

        // Use NFS-aware file check for multi-server deployments
        if (!FileHelper::fileExistsNfs($videoAbsolutePath)) {
            $this->logger->warning('ThumbnailService: Video file not found', [
                'path' => $videoRelativePath,
            ]);

            return null;
        }

        // Determine thumbnail path
        $thumbnailRelativePath = $this->getThumbnailPath($videoRelativePath);
        $thumbnailAbsolutePath = $this->uploadDir.'/'.ltrim($thumbnailRelativePath, '/');

        // Ensure directory exists
        if (!FileHelper::ensureParentDirectory($thumbnailAbsolutePath)) {
            $this->logger->error('ThumbnailService: Failed to create thumbnail directory', [
                'dir' => dirname($thumbnailAbsolutePath),
            ]);

            return null;
        }

        // Generate thumbnail using ffmpeg
        $timestamp = $timestamp ?? self::DEFAULT_TIMESTAMP;
        $result = $this->extractVideoFrame($videoAbsolutePath, $thumbnailAbsolutePath, $timestamp);

        if (!$result) {
            // Try at 0 seconds if 1 second fails (for very short videos)
            if ('00:00:01' === $timestamp) {
                $this->logger->info('ThumbnailService: Retrying at 0 seconds for short video');
                $result = $this->extractVideoFrame($videoAbsolutePath, $thumbnailAbsolutePath, '00:00:00');
            }
        }

        // Use NFS-aware check - thumbnail was just created, might not be visible yet on other servers
        if (!$result || !FileHelper::fileExistsNfs($thumbnailAbsolutePath)) {
            $this->logger->error('ThumbnailService: Failed to generate thumbnail', [
                'video' => $videoRelativePath,
            ]);

            return null;
        }

        // Set proper permissions
        FileHelper::setFilePermissions($thumbnailAbsolutePath);

        $this->logger->info('ThumbnailService: Thumbnail generated', [
            'video' => $videoRelativePath,
            'thumbnail' => $thumbnailRelativePath,
            'size' => filesize($thumbnailAbsolutePath),
        ]);

        return $thumbnailRelativePath;
    }

    /**
     * Get the thumbnail path for a video file.
     *
     * @param string $videoPath relative or absolute path to video
     *
     * @return string thumbnail path (same directory, _thumb.jpg suffix)
     */
    public function getThumbnailPath(string $videoPath): string
    {
        $pathInfo = pathinfo($videoPath);
        $dir = $pathInfo['dirname'] ?? '';
        $basename = $pathInfo['filename'];

        if ('.' === $dir) {
            return $basename.self::THUMBNAIL_SUFFIX;
        }

        return $dir.'/'.$basename.self::THUMBNAIL_SUFFIX;
    }

    /**
     * Check if a thumbnail exists for a video file.
     *
     * Uses NFS-aware file check for multi-server deployments.
     *
     * @param string $videoRelativePath relative path to video
     *
     * @return bool true if thumbnail exists
     */
    public function thumbnailExists(string $videoRelativePath): bool
    {
        $thumbnailPath = $this->getThumbnailPath($videoRelativePath);
        $absolutePath = $this->uploadDir.'/'.ltrim($thumbnailPath, '/');

        return FileHelper::fileExistsNfs($absolutePath);
    }

    /**
     * Get thumbnail path if it exists, null otherwise.
     *
     * @param string $videoRelativePath relative path to video
     *
     * @return string|null relative thumbnail path or null
     */
    public function getThumbnailIfExists(string $videoRelativePath): ?string
    {
        if ($this->thumbnailExists($videoRelativePath)) {
            return $this->getThumbnailPath($videoRelativePath);
        }

        return null;
    }

    /**
     * Delete thumbnail for a video file.
     *
     * @param string $videoRelativePath relative path to video
     *
     * @return bool true if deleted or didn't exist
     */
    public function deleteThumbnail(string $videoRelativePath): bool
    {
        $thumbnailPath = $this->getThumbnailPath($videoRelativePath);
        $absolutePath = $this->uploadDir.'/'.ltrim($thumbnailPath, '/');

        // Use NFS-aware check for multi-server deployments
        if (!FileHelper::fileExistsNfs($absolutePath)) {
            return true; // Already deleted or never existed
        }

        $result = @unlink($absolutePath);

        if ($result) {
            $this->logger->info('ThumbnailService: Thumbnail deleted', [
                'path' => $thumbnailPath,
            ]);
        } else {
            $this->logger->warning('ThumbnailService: Failed to delete thumbnail', [
                'path' => $thumbnailPath,
            ]);
        }

        return $result;
    }

    /**
     * Check if ffmpeg is available.
     */
    public function isFfmpegAvailable(): bool
    {
        $result = @exec('which ffmpeg 2>/dev/null', $output, $returnCode);

        return 0 === $returnCode && !empty($result);
    }

    /**
     * Run ffmpeg to extract a frame from video.
     *
     * This method is public to allow reuse by other services (e.g., OgImageService)
     * that need video frame extraction without duplicating ffmpeg logic.
     *
     * @param string $inputPath  absolute path to video
     * @param string $outputPath absolute path for thumbnail
     * @param string $timestamp  timestamp to extract (HH:MM:SS)
     * @param int    $quality    ffmpeg quality (1-31, lower is better), default: 2
     *
     * @return bool true on success
     */
    public function extractVideoFrame(string $inputPath, string $outputPath, string $timestamp, int $quality = self::DEFAULT_QUALITY): bool
    {
        // Build ffmpeg command
        // -ss: seek to timestamp (before input for speed)
        // -i: input file
        // -vframes 1: extract single frame
        // -q:v: quality (1-31, lower is better)
        // -y: overwrite output without asking
        $command = sprintf(
            'ffmpeg -ss %s -i %s -vframes 1 -q:v %d -y %s 2>&1',
            escapeshellarg($timestamp),
            escapeshellarg($inputPath),
            $quality,
            escapeshellarg($outputPath)
        );

        $output = [];
        $returnCode = 0;

        exec($command, $output, $returnCode);

        if (0 !== $returnCode) {
            $this->logger->debug('ThumbnailService: ffmpeg extraction failed', [
                'command' => $command,
                'return_code' => $returnCode,
                'output' => implode("\n", array_slice($output, -5)),
            ]);

            return false;
        }

        return true;
    }
}
