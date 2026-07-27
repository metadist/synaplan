<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;

/**
 * Resolves model-facing image markers to user-owned local attachments.
 */
final readonly class DocumentImageReferenceResolver
{
    private const SUPPORTED_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    public function __construct(
        private FileRepository $fileRepository,
        private string $uploadDir,
    ) {
    }

    /**
     * Replace request-scoped attachment markers with persistent file markers
     * and return the local image paths needed by the document generator.
     *
     * @return array{content: string, images: array<string, string>}
     */
    public function resolve(string $content, Message $message): array
    {
        $images = [];
        $attachments = $this->imageAttachments($message);

        $content = preg_replace_callback(
            '/\{\{IMAGE:attached:(\d+)}}/',
            function (array $matches) use ($attachments, &$images): string {
                $index = (int) $matches[1] - 1;
                $file = $attachments[$index] ?? null;
                if (!$file instanceof File) {
                    return $matches[0];
                }

                $path = $this->absoluteImagePath($file);
                if (null === $path) {
                    return $matches[0];
                }

                $id = $file->getId();
                $reference = null === $id ? 'attached:'.($index + 1) : 'file:'.$id;
                $images[$reference] = $path;

                return '{{IMAGE:'.$reference.'}}';
            },
            $content,
        ) ?? $content;

        $images = array_replace($images, $this->resolvePersistent($content, $message->getUserId()));

        return ['content' => $content, 'images' => $images];
    }

    /**
     * Resolve persistent markers when regenerating an existing document.
     *
     * @return array<string, string>
     */
    public function resolvePersistent(string $content, int $userId): array
    {
        $images = [];
        if (preg_match_all('/\{\{IMAGE:file:(\d+)}}/', $content, $matches)) {
            foreach (array_unique($matches[1]) as $id) {
                $file = $this->fileRepository->findOneBy([
                    'id' => (int) $id,
                    'userId' => $userId,
                ]);
                if (!$file instanceof File) {
                    continue;
                }

                $path = $this->absoluteImagePath($file);
                if (null !== $path) {
                    $images['file:'.$id] = $path;
                }
            }
        }

        return $images;
    }

    /**
     * @return list<File>
     */
    private function imageAttachments(Message $message): array
    {
        $files = [];
        foreach ($message->getFiles() as $file) {
            if ($this->isSupportedImage($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function isSupportedImage(File $file): bool
    {
        $extension = strtolower(pathinfo($file->getFilePath(), PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    private function absoluteImagePath(File $file): ?string
    {
        if (!$this->isSupportedImage($file)) {
            return null;
        }

        $uploadRoot = realpath($this->uploadDir);
        $path = realpath($this->uploadDir.'/'.ltrim($file->getFilePath(), '/'));
        if (false === $uploadRoot || false === $path || !is_file($path)) {
            return null;
        }

        $rootPrefix = rtrim($uploadRoot, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        if (!str_starts_with($path, $rootPrefix)) {
            return null;
        }

        return $path;
    }
}
