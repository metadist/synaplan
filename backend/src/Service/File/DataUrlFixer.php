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
     * Filename format: {YYYYMM}_{messageId}_{provider}.{ext}
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

        // Extension from MIME
        $ext = match ($mimeType) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'video/mp4' => 'mp4',
            'video/webm' => 'webm',
            'audio/mpeg', 'audio/mp3' => 'mp3',
            'audio/wav' => 'wav',
            default => 'bin',
        };

        // Provider from metadata
        $provider = $message->getMeta('ai_chat_provider') ?? 'unknown';
        $provider = preg_replace('/[^a-z0-9]/', '', strtolower($provider));

        // Filename: YYYYMM_messageId_provider.ext
        $yearMonth = date('Ym', $message->getUnixTimestamp());
        $filename = sprintf('%s_%d_%s.%s', $yearMonth, $message->getId(), $provider, $ext);

        // Ensure upload directory exists
        if (!is_dir($this->uploadDir)) {
            if (!mkdir($this->uploadDir, 0755, true)) {
                $this->logger->error('DataUrlFixer: Failed to create upload directory', [
                    'path' => $this->uploadDir,
                ]);

                return null;
            }
        }

        // Save
        $absolutePath = $this->uploadDir.'/'.$filename;
        if (!file_put_contents($absolutePath, $content)) {
            $this->logger->error('DataUrlFixer: Failed to write file', [
                'path' => $absolutePath,
            ]);

            return null;
        }

        $this->logger->info('DataUrlFixer: Saved file', [
            'filename' => $filename,
            'size' => strlen($content),
            'mime' => $mimeType,
        ]);

        // Return URL path expected by StaticUploadController
        return '/api/v1/files/uploads/'.$filename;
    }
}
