<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\File;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Registers a generated media/document file that a handler already wrote to
 * the upload directory as a {@see File} entity, so callers can attach it to a
 * Message via the Message<->File relation — the only channel that history
 * endpoints serialize (`getFiles()`) and download routes authorize against.
 */
final readonly class GeneratedFileRegistrar
{
    public function __construct(
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * @param string|null $relativePath path relative to the upload dir (handler metadata `local_path`)
     * @param string      $type         handler media type (`audio`/`image`/`video`/...); falls back to the file extension
     */
    public function register(int $userId, ?string $relativePath, string $type): ?File
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        try {
            $absolutePath = $this->uploadDir.'/'.$relativePath;
            $fileSize = is_file($absolutePath) ? (filesize($absolutePath) ?: 0) : 0;
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));

            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath($relativePath);
            $file->setFileType('' !== $type ? $type : $extension);
            $file->setFileName(basename($relativePath));
            $file->setFileSize($fileSize);
            $file->setFileMime($this->mimeForExtension($extension));
            $file->setStatus('generated');

            $this->em->persist($file);
            $this->em->flush();

            return $file;
        } catch (\Throwable $e) {
            $this->logger->warning('GeneratedFileRegistrar: failed to register generated file', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function mimeForExtension(string $extension): string
    {
        return match ($extension) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'mp4' => 'video/mp4',
            'webm' => 'video/webm',
            'mp3' => 'audio/mpeg',
            'wav' => 'audio/wav',
            'ogg' => 'audio/ogg',
            'csv' => 'text/csv',
            'txt' => 'text/plain',
            'md' => 'text/markdown',
            'html' => 'text/html',
            'json' => 'application/json',
            'xml' => 'application/xml',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'pdf' => 'application/pdf',
            'ics' => 'text/calendar',
            default => 'application/octet-stream',
        };
    }
}
