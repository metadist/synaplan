<?php

declare(strict_types=1);

namespace Plugin\Synaform\Service;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the images of one dataset live, and which stored value points at one.
 *
 * An image variable is stored in `field_values` as a small record with a
 * `path`. That record is written by the upload and by the profile-photo
 * extraction only. A record a client sends is never stored, and a stored
 * path is never opened as it is: only its file name counts, and the file has
 * to sit in the image directory of that dataset.
 */
final readonly class CandidateImageStore
{
    private const DEFAULT_MIME = 'application/octet-stream';

    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'bmp' => 'image/bmp',
        'heic' => 'image/heic',
        'heif' => 'image/heif',
    ];

    public function __construct(
        #[Autowire('%app.upload_dir%')] private string $uploadDir,
    ) {
    }

    public function directory(int $userId, string $candidateId): string
    {
        return $this->uploadDir.'/'.$userId.'/synaform/candidates/'.$candidateId.'/images';
    }

    /**
     * The file a stored image value stands for, or null when there is none
     * inside the image directory of this dataset.
     */
    public function locate(int $userId, string $candidateId, mixed $value): ?string
    {
        if (!self::isImageValue($value) || 1 !== preg_match('/^[A-Za-z0-9_-]+$/', $candidateId)) {
            return null;
        }

        $directory = realpath($this->directory($userId, $candidateId));
        $name = basename(str_replace('\\', '/', $value['path']));
        if (false === $directory || '' === $name) {
            return null;
        }

        $file = realpath($directory.'/'.$name);
        if (false === $file || \dirname($file) !== $directory || !is_file($file)) {
            return null;
        }

        return $file;
    }

    /**
     * The content type follows the file on disk, never the stored record.
     */
    public function mimeOf(string $file): string
    {
        return self::MIME_BY_EXTENSION[strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? self::DEFAULT_MIME;
    }

    /**
     * Field values as a client may set them: an image record in the request
     * is dropped, and a stored image stays unless the client replaces that
     * field with a plain value.
     *
     * @param array<string, mixed> $incoming
     * @param array<string, mixed> $stored
     *
     * @return array<string, mixed>
     */
    public function withoutClientImages(array $incoming, array $stored = []): array
    {
        $values = [];
        foreach ($incoming as $key => $value) {
            if (!self::isImageValue($value)) {
                $values[$key] = $value;
            }
        }
        foreach ($stored as $key => $value) {
            if (self::isImageValue($value) && !\array_key_exists($key, $values)) {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @phpstan-assert-if-true array{path: non-empty-string} $value
     */
    private static function isImageValue(mixed $value): bool
    {
        return \is_array($value) && \is_string($value['path'] ?? null) && '' !== $value['path'];
    }
}
