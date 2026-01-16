<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Chat;
use App\Entity\Message;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;

/**
 * Open Graph image generation service.
 *
 * Generates OG images for shared chats by:
 * 1. Finding the first suitable image in chat messages
 * 2. Extracting a frame from videos using ffmpeg
 * 3. Falling back to default Synaplan image
 *
 * Images are stored in a dedicated og/ directory under uploads.
 */
final class OgImageService
{
    private const OG_DIRECTORY = 'og';
    private const DEFAULT_OG_IMAGE = '/og-image.png';
    private const VIDEO_FRAME_TIMESTAMP = '00:00:02'; // 2 seconds into video
    private const VIDEO_FRAME_FALLBACK = '00:00:00'; // 0 seconds for short videos
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    private const VIDEO_EXTENSIONS = ['mp4', 'webm'];

    public function __construct(
        private MessageRepository $messageRepository,
        private DataUrlFixer $dataUrlFixer,
        private ThumbnailService $thumbnailService,
        private string $uploadDir,
        private string $frontendDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Generate OG image for a chat.
     *
     * Scans chat messages for suitable media and creates an OG image.
     * Returns the relative path to the OG image, or null if generation failed.
     *
     * @param Chat $chat The chat to generate OG image for
     *
     * @return string|null Relative path from uploads directory, or null
     */
    public function generateOgImage(Chat $chat): ?string
    {
        $messages = $this->messageRepository->findBy(
            ['chatId' => $chat->getId()],
            ['unixTimestamp' => 'ASC']
        );

        // Strategy 1: Find first suitable image
        $imagePath = $this->findFirstImage($messages);
        if ($imagePath) {
            $ogPath = $this->copyToOgDirectory($chat, $imagePath, 'image');
            if ($ogPath && $this->verifyFileExists($ogPath)) {
                $this->logger->info('OgImageService: Using chat image for OG', [
                    'chat_id' => $chat->getId(),
                    'source' => $imagePath,
                    'og_path' => $ogPath,
                ]);

                return $ogPath;
            }
        }

        // Strategy 2: Extract frame from first video
        $videoPath = $this->findFirstVideo($messages);
        if ($videoPath) {
            $ogPath = $this->extractVideoFrameFromVideo($chat, $videoPath);
            if ($ogPath && $this->verifyFileExists($ogPath)) {
                $this->logger->info('OgImageService: Extracted video frame for OG', [
                    'chat_id' => $chat->getId(),
                    'source' => $videoPath,
                    'og_path' => $ogPath,
                ]);

                return $ogPath;
            }
        }

        // Strategy 3: Use default OG image
        $defaultPath = $this->copyDefaultImage($chat);
        if ($defaultPath && $this->verifyFileExists($defaultPath)) {
            $this->logger->info('OgImageService: Using default OG image', [
                'chat_id' => $chat->getId(),
                'og_path' => $defaultPath,
            ]);

            return $defaultPath;
        }

        $this->logger->warning('OgImageService: Failed to generate OG image', [
            'chat_id' => $chat->getId(),
        ]);

        return null;
    }

    /**
     * Delete OG image for a chat.
     *
     * @param Chat $chat The chat whose OG image should be deleted
     */
    public function deleteOgImage(Chat $chat): void
    {
        $ogPath = $chat->getOgImagePath();
        if (!$ogPath) {
            return;
        }

        $absolutePath = $this->uploadDir.'/'.ltrim($ogPath, '/');
        if (FileHelper::fileExistsNfs($absolutePath)) {
            @unlink($absolutePath);
            $this->logger->info('OgImageService: Deleted OG image', [
                'chat_id' => $chat->getId(),
                'path' => $ogPath,
            ]);
        }
    }

    /**
     * Check if the OG image file exists.
     *
     * @param string $relativePath Relative path from uploads directory
     */
    public function verifyFileExists(string $relativePath): bool
    {
        $absolutePath = $this->uploadDir.'/'.ltrim($relativePath, '/');

        return FileHelper::fileExistsNfs($absolutePath);
    }

    /**
     * Get the full URL for an OG image.
     *
     * @param string $relativePath Relative path from uploads directory
     * @param string $baseUrl      Base URL (e.g., https://web.synaplan.com)
     *
     * @return string Full URL to the OG image
     */
    public function getOgImageUrl(string $relativePath, string $baseUrl): string
    {
        return rtrim($baseUrl, '/').'/api/v1/files/uploads/'.ltrim($relativePath, '/');
    }

    /**
     * Find first suitable image in messages.
     */
    private function findFirstImage(array $messages): ?string
    {
        foreach ($messages as $message) {
            $filePath = $this->resolveFilePath($message);
            if (!$filePath) {
                continue;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                // Verify image exists before returning
                $absolutePath = $this->getAbsolutePathFromApiPath($filePath);
                if (FileHelper::fileExistsNfs($absolutePath)) {
                    return $filePath;
                }
            }
        }

        return null;
    }

    /**
     * Find first video in messages.
     */
    private function findFirstVideo(array $messages): ?string
    {
        foreach ($messages as $message) {
            $filePath = $this->resolveFilePath($message);
            if (!$filePath) {
                continue;
            }

            $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
            if (in_array($extension, self::VIDEO_EXTENSIONS, true)) {
                // Verify video exists before returning
                $absolutePath = $this->getAbsolutePathFromApiPath($filePath);
                if (FileHelper::fileExistsNfs($absolutePath)) {
                    return $filePath;
                }
            }
        }

        return null;
    }

    /**
     * Resolve file path from message, handling data URLs.
     */
    private function resolveFilePath(Message $message): ?string
    {
        $filePath = $message->getFilePath();
        if (!$filePath) {
            return null;
        }

        // Fix data URL to file if needed
        if (str_starts_with($filePath, 'data:')) {
            $filePath = $this->dataUrlFixer->ensureFileOnDisk($message);
        }

        return $filePath ?: null;
    }

    /**
     * Convert API path to absolute filesystem path.
     *
     * @param string $apiPath Path like "/api/v1/files/uploads/02/000/..."
     *
     * @return string Absolute path
     */
    private function getAbsolutePathFromApiPath(string $apiPath): string
    {
        if (str_starts_with($apiPath, '/api/v1/files/uploads/')) {
            $relativePath = substr($apiPath, strlen('/api/v1/files/uploads/'));

            return $this->uploadDir.'/'.ltrim($relativePath, '/');
        }

        // Already a relative path
        return $this->uploadDir.'/'.ltrim($apiPath, '/');
    }

    /**
     * Copy an image to the OG directory.
     *
     * @param Chat   $chat       The chat
     * @param string $sourcePath Source file path (API path or relative)
     * @param string $type       Type identifier for filename
     *
     * @return string|null Relative path in og/ directory, or null on failure
     */
    private function copyToOgDirectory(Chat $chat, string $sourcePath, string $type): ?string
    {
        $absoluteSource = $this->getAbsolutePathFromApiPath($sourcePath);
        if (!FileHelper::fileExistsNfs($absoluteSource)) {
            return null;
        }

        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $ogPath = $this->generateOgPath($chat, $extension);
        $absoluteTarget = $this->uploadDir.'/'.$ogPath;

        // Ensure directory exists
        if (!FileHelper::ensureParentDirectory($absoluteTarget)) {
            $this->logger->error('OgImageService: Failed to create OG directory', [
                'dir' => dirname($absoluteTarget),
            ]);

            return null;
        }

        // Copy file
        if (!@copy($absoluteSource, $absoluteTarget)) {
            $this->logger->error('OgImageService: Failed to copy image', [
                'source' => $absoluteSource,
                'target' => $absoluteTarget,
            ]);

            return null;
        }

        FileHelper::setFilePermissions($absoluteTarget);

        return $ogPath;
    }

    /**
     * Extract a frame from a video file.
     *
     * Uses ThumbnailService for video frame extraction to avoid code duplication.
     *
     * @param Chat   $chat      The chat
     * @param string $videoPath Video file path (API path)
     *
     * @return string|null Relative path to extracted frame, or null on failure
     */
    private function extractVideoFrameFromVideo(Chat $chat, string $videoPath): ?string
    {
        $absoluteVideo = $this->getAbsolutePathFromApiPath($videoPath);
        if (!FileHelper::fileExistsNfs($absoluteVideo)) {
            return null;
        }

        $ogPath = $this->generateOgPath($chat, 'jpg');
        $absoluteTarget = $this->uploadDir.'/'.$ogPath;

        // Ensure directory exists
        if (!FileHelper::ensureParentDirectory($absoluteTarget)) {
            return null;
        }

        // Try extracting at 2 seconds first using shared ThumbnailService
        if ($this->thumbnailService->extractVideoFrame($absoluteVideo, $absoluteTarget, self::VIDEO_FRAME_TIMESTAMP)) {
            FileHelper::setFilePermissions($absoluteTarget);

            return $ogPath;
        }

        // Try at 0 seconds for short videos
        if ($this->thumbnailService->extractVideoFrame($absoluteVideo, $absoluteTarget, self::VIDEO_FRAME_FALLBACK)) {
            FileHelper::setFilePermissions($absoluteTarget);

            return $ogPath;
        }

        return null;
    }

    /**
     * Copy default OG image.
     *
     * Tries multiple sources in order:
     * 1. Custom og-image.png (ideal 1200x630)
     * 2. apple-touch-icon.png (fallback)
     *
     * @param Chat $chat The chat
     *
     * @return string|null Relative path to copied default image, or null on failure
     */
    private function copyDefaultImage(Chat $chat): ?string
    {
        // List of fallback images to try (in order of preference)
        $fallbackImages = [
            self::DEFAULT_OG_IMAGE,     // /og-image.png (ideal 1200x630)
            '/apple-touch-icon.png',     // Standard favicon (smaller but better than nothing)
        ];

        $defaultSource = null;
        foreach ($fallbackImages as $imagePath) {
            // Try frontend public directory first (development)
            $candidate = $this->frontendDir.'/public'.$imagePath;
            if (is_file($candidate)) {
                $defaultSource = $candidate;
                break;
            }

            // Fallback to built frontend directory (production)
            $candidate = '/var/www/frontend'.$imagePath;
            if (is_file($candidate)) {
                $defaultSource = $candidate;
                break;
            }
        }

        if (!$defaultSource) {
            $this->logger->warning('OgImageService: No default OG image found', [
                'searched' => $fallbackImages,
            ]);

            return null;
        }

        $extension = strtolower(pathinfo($defaultSource, PATHINFO_EXTENSION));
        $ogPath = $this->generateOgPath($chat, $extension);
        $absoluteTarget = $this->uploadDir.'/'.$ogPath;

        // Ensure directory exists
        if (!FileHelper::ensureParentDirectory($absoluteTarget)) {
            return null;
        }

        // Copy file
        if (!@copy($defaultSource, $absoluteTarget)) {
            $this->logger->error('OgImageService: Failed to copy default image', [
                'source' => $defaultSource,
                'target' => $absoluteTarget,
            ]);

            return null;
        }

        FileHelper::setFilePermissions($absoluteTarget);

        return $ogPath;
    }

    /**
     * Generate OG image path for a chat.
     *
     * @param Chat   $chat      The chat
     * @param string $extension File extension
     *
     * @return string Relative path like "og/12/abc123def456.jpg"
     */
    private function generateOgPath(Chat $chat, string $extension): string
    {
        $chatId = $chat->getId();
        // Use chat ID mod 100 for directory sharding
        $shard = str_pad((string) ($chatId % 100), 2, '0', STR_PAD_LEFT);
        // Use share token or generate unique identifier
        $identifier = $chat->getShareToken() ?? bin2hex(random_bytes(12));

        return sprintf('%s/%s/%s.%s', self::OG_DIRECTORY, $shard, $identifier, $extension);
    }

}
