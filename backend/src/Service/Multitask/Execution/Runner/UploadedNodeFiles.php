<?php

declare(strict_types=1);

namespace App\Service\Multitask\Execution\Runner;

use App\Service\File\UserUploadPathBuilder;
use Psr\Log\LoggerInterface;

/**
 * Turns upstream `$nX.file` descriptors into files under the acting user's
 * own uploads directory. The files leave Synaplan, so a descriptor that
 * points at another person's upload, or outside the uploads tree, is skipped.
 */
final readonly class UploadedNodeFiles
{
    private const SERVE_PREFIX = '/api/v1/files/uploads/';

    public function __construct(
        private LoggerInterface $logger,
        private string $uploadDir = '/var/www/backend/var/uploads',
        private UserUploadPathBuilder $paths = new UserUploadPathBuilder(),
    ) {
    }

    /**
     * @return list<array{path: string, name: string}>
     */
    public function resolve(mixed $value, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $out = [];
        foreach ($this->flatten($value) as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }
            $absolute = $this->absolutePath($candidate, $userId);
            if (null === $absolute) {
                continue;
            }
            $name = is_string($candidate['name'] ?? null) && '' !== $candidate['name'] ? $candidate['name'] : basename($absolute);
            $out[] = ['path' => $absolute, 'name' => $name];
        }

        return $out;
    }

    /**
     * @param array<mixed> $descriptor
     */
    private function absolutePath(array $descriptor, int $userId): ?string
    {
        $relative = is_string($descriptor['local_path'] ?? null) ? $descriptor['local_path'] : '';
        $url = is_string($descriptor['path'] ?? null) ? $descriptor['path'] : '';
        if ('' === trim($relative) && str_starts_with($url, self::SERVE_PREFIX)) {
            $relative = substr($url, strlen(self::SERVE_PREFIX));
        }
        if ('' === trim($relative)) {
            return null;
        }

        $root = realpath(rtrim($this->uploadDir, '/')) ?: rtrim($this->uploadDir, '/');
        $userBase = $root.DIRECTORY_SEPARATOR.$this->paths->buildUserBaseRelativePath($userId);
        $resolved = realpath($root.'/'.ltrim($relative, '/'));
        if (false === $resolved || !str_starts_with($resolved, $userBase.DIRECTORY_SEPARATOR) || !is_file($resolved)) {
            $this->logger->warning('UploadedNodeFiles: file not found or not in the user\'s uploads', [
                'user_id' => $userId,
                'path' => $url,
                'local_path' => $relative,
            ]);

            return null;
        }

        return $resolved;
    }

    /**
     * @return list<mixed>
     */
    private function flatten(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (is_array($item) && !isset($item['path'])) {
                foreach ($item as $sub) {
                    $out[] = $sub;
                }
            } else {
                $out[] = $item;
            }
        }

        return $out;
    }
}
