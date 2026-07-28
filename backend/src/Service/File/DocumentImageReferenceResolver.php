<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;
use Psr\Log\LoggerInterface;

/**
 * Resolves model-facing image markers to user-owned local attachments.
 */
final readonly class DocumentImageReferenceResolver
{
    private const SUPPORTED_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    public function __construct(
        private FileRepository $fileRepository,
        private LoggerInterface $logger,
        private string $uploadDir,
    ) {
    }

    /**
     * Replace request-scoped attachment markers with persistent file markers
     * and return the local image paths needed by the document generator.
     *
     * Markers the model invented — an attachment index the message does not
     * have, or a file the user no longer owns — are dropped from the content.
     * The document is generated without that image; the alternative (keeping an
     * unresolvable marker) costs the user the whole document.
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

        return [
            'content' => $this->dropUnresolvedMarkers($content, $images, $message),
            'images' => $images,
        ];
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
     * Remove every image marker without a resolved local path, so no document
     * writer ever sees a reference it cannot embed and the persisted document
     * text does not carry the dead marker into the next edit.
     *
     * @param array<string, string> $images
     */
    private function dropUnresolvedMarkers(string $content, array $images, Message $message): string
    {
        $dropped = [];
        $cleaned = preg_replace_callback(
            '/\{\{IMAGE:([a-z]+:\d+)}}/',
            static function (array $matches) use ($images, &$dropped): string {
                if (isset($images[$matches[1]])) {
                    return $matches[0];
                }

                $dropped[] = $matches[1];

                return '';
            },
            $content,
        ) ?? $content;

        if ([] === $dropped) {
            return $content;
        }

        $this->logger->warning('DocumentImageReferenceResolver: dropped unresolvable image markers', [
            'references' => array_values(array_unique($dropped)),
            'message_id' => $message->getId(),
            'user_id' => $message->getUserId(),
            'attached_images' => count($this->imageAttachments($message)),
        ]);

        // A marker on its own line leaves an empty paragraph behind.
        return preg_replace('/\R{3,}/', "\n\n", $cleaned) ?? $cleaned;
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
