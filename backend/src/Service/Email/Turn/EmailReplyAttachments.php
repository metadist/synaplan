<?php

declare(strict_types=1);

namespace App\Service\Email\Turn;

use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The files an email reply carries: the answer's generated media or
 * document and the extra outputs of a multi-step answer, resolved to
 * absolute paths under the upload directory. A path outside it, a missing
 * file or a type that is not attachable is left out.
 */
final readonly class EmailReplyAttachments
{
    private const ATTACHABLE = ['image', 'video', 'audio', 'document'];
    private const SERVE_PREFIX = '/api/v1/files/uploads/';

    public function __construct(
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $uploadDir,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The absolute path of a file the turn just stored under the upload directory.
     */
    public function uploaded(string $relativePath): string
    {
        return $this->uploadDir.'/'.$relativePath;
    }

    /**
     * The answer's own file. Multi-step answers name its type only on the
     * file descriptor, so documents (an .ics, say) still attach.
     *
     * @param array<string, mixed> $metadata
     */
    public function primary(array $metadata): ?string
    {
        $mediaType = strtolower((string) ($metadata['media_type'] ?? ''));
        if ('' === $mediaType && is_string($metadata['file']['type'] ?? null)) {
            $mediaType = strtolower($metadata['file']['type']);
        }
        $relativePath = is_string($metadata['local_path'] ?? null) ? $metadata['local_path'] : null;
        $filePathUrl = is_string($metadata['file']['path'] ?? null) ? $metadata['file']['path'] : null;

        return $this->resolve($mediaType, $relativePath, $filePathUrl);
    }

    /**
     * The outputs of a multi-step answer beyond its first file; none for a
     * single file.
     *
     * @param array<string, mixed> $metadata
     *
     * @return list<string>
     */
    public function additional(array $metadata): array
    {
        $files = $metadata['files'] ?? null;
        if (!is_array($files) || count($files) < 2) {
            return [];
        }

        $paths = [];
        foreach (array_slice(array_values($files), 1) as $file) {
            if (!is_array($file)) {
                continue;
            }
            $path = $this->resolve(
                strtolower((string) ($file['type'] ?? '')),
                is_string($file['local_path'] ?? null) ? $file['local_path'] : null,
                is_string($file['path'] ?? null) ? $file['path'] : null,
            );
            if (null !== $path) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * A relative path, or the one inside a serve URL, resolved with a path
     * traversal guard.
     */
    private function resolve(string $mediaType, ?string $relativePath, ?string $filePathUrl): ?string
    {
        if (!in_array($mediaType, self::ATTACHABLE, true)) {
            return null;
        }

        if ((!is_string($relativePath) || '' === trim($relativePath)) && is_string($filePathUrl) && str_starts_with($filePathUrl, self::SERVE_PREFIX)) {
            $relativePath = substr($filePathUrl, strlen(self::SERVE_PREFIX));
        }
        if (!is_string($relativePath) || '' === trim($relativePath)) {
            return null;
        }

        $absolutePath = $this->uploadDir.'/'.ltrim($relativePath, '/');
        $resolvedPath = realpath($absolutePath);
        $isWithinUploads = false !== $resolvedPath
            && ($resolvedPath === $this->uploadDir || str_starts_with($resolvedPath, $this->uploadDir.DIRECTORY_SEPARATOR));

        if (false === $resolvedPath || !$isWithinUploads || !is_file($resolvedPath)) {
            $this->logger->warning('Email attachment not found or outside allowed directory', [
                'media_type' => $mediaType,
                'relative_path' => $relativePath,
                'absolute_path' => $absolutePath,
                'resolved_path' => $resolvedPath,
            ]);

            return null;
        }

        return $resolvedPath;
    }
}
