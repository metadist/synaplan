<?php

namespace App\Service\File;

use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * File Storage Service.
 *
 * Handles file uploads, storage, and management.
 * Creates organized directory structure (under var/uploads):
 * {last2}/{prev3}/{paddedUserId}/{year}/{month}/
 */
class FileStorageService
{
    private const MAX_FILE_SIZE = 128 * 1024 * 1024; // 128 MB
    private const ALLOWED_EXTENSIONS = [
        'pdf', 'docx', 'doc', 'xlsx', 'xls', 'pptx', 'ppt', 'txt', 'md', 'csv',
        'jpg', 'jpeg', 'png', 'gif', 'webp',
        'mp3', 'mp4', 'wav', 'ogg', 'm4a', 'webm',
    ];

    public function __construct(
        private string $uploadDir,
        private LoggerInterface $logger,
        private UserUploadPathBuilder $userUploadPathBuilder,
    ) {
    }

    /**
     * Store uploaded file.
     *
     * @param UploadedFile $file   Uploaded file
     * @param int          $userId User ID
     *
     * @return array ['success' => bool, 'path' => string, 'size' => int, 'mime' => string, 'error' => string|null]
     */
    public function storeUploadedFile(UploadedFile $file, int $userId): array
    {
        // Validate file
        $validation = $this->validateFile($file);
        if (!$validation['valid']) {
            return [
                'success' => false,
                'path' => '',
                'size' => 0,
                'mime' => '',
                'error' => $validation['error'],
            ];
        }

        try {
            // FrankenPHP compatibility: Check if file exists first
            $tempPath = $file->getPathname();
            $tempExists = file_exists($tempPath);
            $fileSize = $file->getSize();

            $this->logger->info('FileStorage: Starting file store', [
                'temp_path' => $tempPath,
                'temp_exists' => $tempExists,
                'isValid' => $tempExists, // FrankenPHP workaround
                'original_name' => $file->getClientOriginalName(),
                'size' => $fileSize,
            ]);

            // Check for cloud-only files (Dropbox, iCloud, OneDrive, etc.)
            // On Mac, these services use placeholder files with 0 bytes until downloaded
            if (0 === $fileSize || ($tempExists && 0 === filesize($tempPath))) {
                $this->logger->warning('FileStorage: Empty file detected (likely cloud-only)', [
                    'original_name' => $file->getClientOriginalName(),
                    'temp_path' => $tempPath,
                ]);

                return [
                    'success' => false,
                    'path' => '',
                    'size' => 0,
                    'mime' => '',
                    'error' => 'File appears to be empty. If using Dropbox, iCloud, or OneDrive, please download the file locally first before uploading (right-click → "Make available offline" or similar).',
                ];
            }

            // Skip isValid() check for FrankenPHP compatibility
            // FrankenPHP may delete temp files immediately, so we use getContent() instead

            // Generate storage path: {last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
            $relativePath = $this->generateStoragePath($userId, $file->getClientOriginalName());
            $absolutePath = $this->uploadDir.'/'.$relativePath;

            // Create directory if not exists
            $dir = dirname($absolutePath);
            if (!is_dir($dir)) {
                if (!mkdir($dir, 0775, true)) {
                    $this->logger->error('FileStorage: Failed to create directory', ['dir' => $dir]);

                    return [
                        'success' => false,
                        'path' => '',
                        'size' => 0,
                        'mime' => '',
                        'error' => 'Failed to create storage directory',
                    ];
                }
            }

            // FrankenPHP workaround: Always use getContent() instead of move()
            // This is necessary because FrankenPHP deletes temp files immediately
            $this->logger->info('FileStorage: Using stream copy for FrankenPHP compatibility');
            $content = $file->getContent();
            if (!file_put_contents($absolutePath, $content)) {
                throw new \RuntimeException('Failed to write file content to '.$absolutePath);
            }

            $this->logger->info('FileStorage: File stored successfully', [
                'user_id' => $userId,
                'path' => $relativePath,
                'size' => filesize($absolutePath),
                'mime' => $file->getMimeType(),
            ]);

            return [
                'success' => true,
                'path' => $relativePath,
                'size' => filesize($absolutePath),
                'mime' => $file->getMimeType() ?? 'application/octet-stream',
                'error' => null,
            ];
        } catch (\Throwable $e) {
            $this->logger->error('FileStorage: Failed to store file', [
                'user_id' => $userId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'path' => '',
                'size' => 0,
                'mime' => '',
                'error' => 'Failed to store file: '.$e->getMessage(),
            ];
        }
    }

    /**
     * Validate uploaded file.
     */
    private function validateFile(UploadedFile $file): array
    {
        // Check file size
        if ($file->getSize() > self::MAX_FILE_SIZE) {
            return [
                'valid' => false,
                'error' => 'File too large. Maximum size is '.(self::MAX_FILE_SIZE / 1024 / 1024).' MB',
            ];
        }

        // Check extension
        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return [
                'valid' => false,
                'error' => 'File type not allowed. Allowed: '.implode(', ', self::ALLOWED_EXTENSIONS),
            ];
        }

        // Check if file was uploaded without errors
        if (UPLOAD_ERR_OK !== $file->getError()) {
            return [
                'valid' => false,
                'error' => 'File upload error: '.$this->getUploadErrorMessage($file->getError()),
            ];
        }

        return ['valid' => true, 'error' => null];
    }

    /**
     * Generate storage path with timestamp and sanitized filename.
     */
    private function generateStoragePath(int $userId, string $originalFilename): string
    {
        $year = date('Y');
        $month = date('m');
        $timestamp = time();

        // Sanitize filename: keep only alphanumeric, dots, hyphens, underscores
        $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $originalFilename);
        $sanitized = preg_replace('/_+/', '_', $sanitized);

        // Add timestamp to prevent collisions
        $extension = pathinfo($sanitized, PATHINFO_EXTENSION);
        $basename = pathinfo($sanitized, PATHINFO_FILENAME);
        $filename = $basename.'_'.$timestamp.'.'.$extension;

        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);

        return $userBase.'/'.$year.'/'.$month.'/'.$filename;
    }

    /**
     * Get absolute path from relative path.
     */
    public function getAbsolutePath(string $relativePath): string
    {
        return $this->uploadDir.'/'.ltrim($relativePath, '/');
    }

    /**
     * Get absolute base directory path for a user (without year/month/filename).
     */
    public function getUserBaseAbsolutePath(int $userId): string
    {
        $relative = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);

        return $this->getAbsolutePath($relative);
    }

    /**
     * Check if file exists.
     */
    public function fileExists(string $relativePath): bool
    {
        return is_file($this->getAbsolutePath($relativePath));
    }

    /**
     * Delete file.
     */
    public function deleteFile(string $relativePath): bool
    {
        $absolutePath = $this->getAbsolutePath($relativePath);

        if (!is_file($absolutePath)) {
            return false;
        }

        return @unlink($absolutePath);
    }

    /**
     * Get upload error message.
     */
    private function getUploadErrorMessage(int $errorCode): string
    {
        return match ($errorCode) {
            UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize directive',
            UPLOAD_ERR_FORM_SIZE => 'File exceeds MAX_FILE_SIZE directive',
            UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded',
            UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary folder',
            UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk',
            UPLOAD_ERR_EXTENSION => 'File upload stopped by extension',
            default => 'Unknown upload error',
        };
    }
}
