<?php

declare(strict_types=1);

namespace App\Service\File;

/**
 * The upload-dir-relative path a `/api/v1/files/uploads/{path}` request names.
 *
 * The router has already decoded `%2e%2e` and `%2f`, and every path the server
 * stores is canonical, so a `.` or `..` segment never names a real upload: it
 * can only point outside the directory the path starts with. Such a path has
 * no instance, so no access decision is ever made on it.
 */
final readonly class UploadRequestPath
{
    /**
     * Served without an access check: the preview images of shared chats and
     * the chat widget icons third-party pages embed.
     */
    private const PUBLIC_DIRECTORIES = ['og', 'widget-icons'];

    private function __construct(
        public string $relative,
    ) {
    }

    /**
     * Null for a path with a `.` or `..` segment or a NUL byte.
     */
    public static function fromRoute(string $path): ?self
    {
        if (str_contains($path, "\0")) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ('.' === $segment || '..' === $segment) {
                return null;
            }
        }

        return new self($path);
    }

    public function isPublicAsset(): bool
    {
        return in_array(strstr($this->relative, '/', true), self::PUBLIC_DIRECTORIES, true);
    }
}
