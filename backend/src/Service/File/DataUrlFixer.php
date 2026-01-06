<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Message;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Fixes data URLs in BFILEPATH by converting them to files on disk.
 *
 * Call ensureFileOnDisk() after loading a Message entity. If the path
 * is a data URL, it will be saved to disk and the entity updated.
 */
final class DataUrlFixer
{
    public function __construct(
        private EntityManagerInterface $em,
        private UserUploadPathBuilder $userUploadPathBuilder,
        private string $uploadDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Ensure the message's file path points to an actual file, not a data URL.
     *
     * If BFILEPATH starts with "data:", decode and save to disk, then update the entity.
     *
     * @return string the corrected file path (or original if already correct)
     */
    public function ensureFileOnDisk(Message $message): string
    {
        $filePath = $message->getFilePath();

        // Nothing to fix if empty or already a proper path
        if (empty($filePath) || !str_starts_with($filePath, 'data:')) {
            return $filePath;
        }

        // Convert data URL to file
        $newPath = $this->saveDataUrlAsFile($filePath, $message);

        if ($newPath) {
            $message->setFilePath($newPath);
            $this->em->flush();

            $this->logger->info('DataUrlFixer: Converted data URL to file', [
                'message_id' => $message->getId(),
                'new_path' => $newPath,
            ]);
        }

        return $newPath ?: '';
    }

    /**
     * Save a data URL as a file on disk.
     *
     * Filename format: {messageId}_{provider}_{timestamp}.{ext}
     * Path format: {userBase}/{year}/{month}/{filename}
     */
    private function saveDataUrlAsFile(string $dataUrl, Message $message): ?string
    {
        // Parse: data:image/png;base64,XXXX
        if (!preg_match('#^data:([^;]+);base64,(.+)$#', $dataUrl, $matches)) {
            $this->logger->error('DataUrlFixer: Invalid data URL format', [
                'message_id' => $message->getId(),
            ]);

            return null;
        }

        $mimeType = $matches[1];
        $base64Data = $matches[2];
        $content = base64_decode($base64Data, true);

        if (false === $content || '' === $content) {
            $this->logger->error('DataUrlFixer: Failed to decode base64', [
                'message_id' => $message->getId(),
            ]);

            return null;
        }

        // Validate decoded content matches declared MIME type
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->buffer($content);
        if ($detectedMime && !str_starts_with($detectedMime, explode('/', $mimeType)[0])) {
            $this->logger->error('DataUrlFixer: MIME type mismatch', [
                'message_id' => $message->getId(),
                'declared' => $mimeType,
                'detected' => $detectedMime,
            ]);

            return null;
        }

        // Extension and provider from metadata
        $ext = FileHelper::getExtensionFromMimeType($mimeType);
        $provider = FileHelper::sanitizeProviderName($message->getMeta('ai_chat_provider') ?? 'unknown');

        // Generate filename: messageId_provider_timestamp.ext
        $timestamp = time();
        $filename = sprintf('%d_%s_%d.%s', $message->getId(), $provider, $timestamp, $ext);

        // Build storage path with user subdirectories: {last2}/{prev3}/{paddedUserId}/{year}/{month}/{filename}
        $userId = $message->getUserId();
        $year = date('Y');
        $month = date('m');
        $userBase = $this->userUploadPathBuilder->buildUserBaseRelativePath($userId);
        $relativePath = $userBase.'/'.$year.'/'.$month.'/'.$filename;
        $absolutePath = $this->uploadDir.'/'.$relativePath;

        // Create directory if not exists
        $dir = dirname($absolutePath);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $this->logger->error('DataUrlFixer: Failed to create upload directory', [
                'dir' => $dir,
            ]);

            return null;
        }

        // Save file
        if (!file_put_contents($absolutePath, $content)) {
            $this->logger->error('DataUrlFixer: Failed to write file', [
                'path' => $absolutePath,
            ]);

            return null;
        }

        $this->logger->info('DataUrlFixer: Saved file', [
            'relative_path' => $relativePath,
            'size' => strlen($content),
            'mime' => $mimeType,
        ]);

        // Return URL path expected by StaticUploadController
        return '/api/v1/files/uploads/'.$relativePath;
    }
}
