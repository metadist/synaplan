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
     * @param bool        $ephemeral    incognito-session artefact: hidden from listings, deleted after the session
     */
    public function register(int $userId, ?string $relativePath, string $type, ?int $messageId = null, ?string $provider = null, bool $ephemeral = false): ?File
    {
        if (null === $relativePath || '' === $relativePath) {
            return null;
        }

        // Normalise a stored display URL down to the upload-dir-relative path
        // BFILES.BFILEPATH expects. Some callers (and legacy BMESSAGES rows)
        // persist the public "/api/v1/files/uploads/<rel>" serve URL instead of
        // the raw relative path; storing that verbatim would break the file
        // list/serve/thumb routes which resolve uploadDir + relative path.
        $relativePath = $this->normalizeRelativePath($relativePath);

        // A generated artefact whose "path" is actually an inlined data: URI
        // (legacy rows stored the full base64 image in the path column) cannot
        // become a file row: it has no on-disk location and would overflow
        // BFILEPATH (varchar 255) with a "Data too long" error that closes the
        // EntityManager and aborts the whole batch. Skip it safely instead.
        if (str_starts_with($relativePath, 'data:') || strlen($relativePath) > 255) {
            $this->logger->warning('GeneratedFileRegistrar: skipping unstorable generated path (data URI or over 255 chars)', [
                'length' => strlen($relativePath),
                'prefix' => substr($relativePath, 0, 32),
            ]);

            return null;
        }

        try {
            // Idempotency: never create a second BFILES row for the same
            // (user, path). Makes re-runs safe (backfill, reaper retries, the
            // inline + async media paths both reaching the same file).
            $existing = $this->files->findOneBy(['userId' => $userId, 'filePath' => $relativePath]);
            if ($existing instanceof File) {
                return $existing;
            }

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
            // Every generated artefact starts un-indexed; the user can add its
            // generation prompt to the knowledge base on demand from the file
            // manager ("Add prompt to knowledge base").
            $file->setVectorState(File::VECTOR_STATE_NONE);
            $file->setEphemeral($ephemeral);

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
     * Reduce a stored media path to the upload-dir-relative form that
     * BFILES.BFILEPATH stores. Accepts an absolute public URL
     * (`https://host/api/v1/files/uploads/<rel>`) or the route-relative
     * `/api/v1/files/uploads/<rel>` display path and returns `<rel>`. A path
     * that is already relative is returned unchanged (minus any leading slash).
     */
    private function normalizeRelativePath(string $path): string
    {
        // Absolute public URL → keep only the path component.
        if (1 === preg_match('#^https?://[^/]+(/.*)$#i', $path, $m)) {
            $path = $m[1];
        }

        // Strip the serve-route prefix, with or without a leading slash.
        $stripped = preg_replace('#^/?api/v1/files/uploads/#', '', $path);
        $path = null === $stripped ? $path : $stripped;

        return ltrim($path, '/');
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
