<?php

declare(strict_types=1);

namespace App\Service\Media;

use App\Entity\File;
use App\Repository\FileRepository;
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
        private FileRepository $files,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * @param string|null $relativePath path relative to the upload dir (handler metadata `local_path`)
     * @param string      $type         handler media type (`audio`/`image`/`video`/...); falls back to the file extension
     * @param int|null    $messageId    originating BMESSAGES.BID, for "jump to chat" (03_file-management.md §3.1)
     * @param string|null $provider     generating provider/model, for the Generated gallery
     */
    public function register(int $userId, ?string $relativePath, string $type, ?int $messageId = null, ?string $provider = null): ?File
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        try {
            $absolutePath = $this->uploadDir.'/'.$relativePath;
            $fileSize = is_file($absolutePath) ? (filesize($absolutePath) ?: 0) : 0;
            $extension = strtolower(pathinfo($relativePath, PATHINFO_EXTENSION));
            $fileType = '' !== $type ? $type : $extension;
            $originKind = $this->deriveOriginKind($fileType, $extension);

            $file = new File();
            $file->setUserId($userId);
            $file->setFilePath($relativePath);
            $file->setFileType($fileType);
            $file->setFileName(basename($relativePath));
            $file->setFileSize($fileSize);
            $file->setFileMime($this->mimeForExtension($extension));
            $file->setStatus('generated');
            // G1: every generated artefact becomes a first-class BFILES row so it
            // shows in the file manager's Generated gallery (03_file-management.md
            // §3.2), not just generated documents as before.
            $file->setSource('generated');
            $file->setOriginKind($originKind);
            $file->setMessageId($messageId);
            $file->setProvider($provider);
            // Media (image/video/audio/calendar) is not a RAG document; generated
            // documents stay "none" so they can be vectorized on demand.
            $file->setVectorState(
                'document' === $originKind ? File::VECTOR_STATE_NONE : File::VECTOR_STATE_NOT_APPLICABLE
            );

            $this->files->save($file);

            return $file;
        } catch (\Throwable $e) {
            $this->logger->warning('GeneratedFileRegistrar: failed to register generated file', [
                'path' => $relativePath,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Map a handler media type / extension to a generated origin kind
     * (one of {@see File::ORIGIN_KINDS}). Defaults to `document`.
     */
    private function deriveOriginKind(string $type, string $extension): string
    {
        $type = strtolower($type);
        if (in_array($type, File::ORIGIN_KINDS, true)) {
            return $type;
        }

        return match ($extension) {
            'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg' => 'image',
            'mp4', 'webm', 'mov', 'avi', 'mkv' => 'video',
            'mp3', 'wav', 'ogg', 'm4a' => 'audio',
            'ics' => 'calendar',
            default => 'document',
        };
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
