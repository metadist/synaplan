<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;
use App\Repository\FileRepository;

/**
 * Collects the images a document-generation turn may embed and renders them for
 * the model.
 *
 * The model can only reference an image it knows about: without this catalog it
 * guesses `{{IMAGE:attached:1}}` for a picture generated three turns ago, the
 * marker cannot be resolved and the image silently never appears (#1382). The
 * catalog is the single source of truth — every entry it advertises is one
 * {@see DocumentImageReferenceResolver} accepts back, and nothing else is
 * offered.
 */
final readonly class DocumentImageCatalog
{
    public const SUPPORTED_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    /** Keep the prompt block short — the newest images are the relevant ones. */
    private const MAX_IMAGES = 8;

    /** Upper bound for the thread lookup before validation and capping. */
    private const THREAD_LOOKUP_LIMIT = 30;

    public function __construct(
        private FileRepository $fileRepository,
        private string $uploadDir,
    ) {
    }

    /**
     * Build the list of images this turn may place into the document.
     *
     * Ordered by how likely the user means them: attachments of the current
     * message, then files produced by an upstream node of the same multitask
     * turn, then the images of the conversation, newest first.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param list<string>                                             $extraPaths upload-dir-relative paths produced in this turn (multitask upstream nodes)
     *
     * @return list<DocumentImage>
     */
    public function build(Message $message, array $thread = [], array $extraPaths = []): array
    {
        $userId = (int) $message->getUserId();
        $images = [];
        $seenIds = [];
        $seenPaths = [];

        foreach ($this->attachments($message) as $index => $file) {
            $this->collect($images, $seenIds, $seenPaths, $file, DocumentImage::ORIGIN_ATTACHED, $index + 1);
        }

        foreach ($this->filesForPaths($userId, $extraPaths) as $file) {
            $this->collect($images, $seenIds, $seenPaths, $file, DocumentImage::ORIGIN_GENERATED);
        }

        foreach ($this->threadImages($userId, $thread) as $file) {
            if (count($images) >= self::MAX_IMAGES) {
                break;
            }
            $this->collect($images, $seenIds, $seenPaths, $file, $this->originOf($file));
        }

        return array_slice($images, 0, self::MAX_IMAGES);
    }

    /**
     * Render the catalog as a system-prompt block. An empty catalog produces an
     * explicit "no images" instruction: without it the model invents a marker
     * and the user gets a document with a missing picture instead of a plain one.
     *
     * @param list<DocumentImage> $images
     */
    public function renderPromptBlock(array $images): string
    {
        if ([] === $images) {
            return "\n\n## Images available for this document\n\n"
                ."There are NO images available for this document. Do NOT write any\n"
                ."`{{IMAGE:...}}` marker. If the user asks for an image, generate the\n"
                ."document without it.\n";
        }

        $lines = '';
        foreach ($images as $image) {
            $lines .= '- `'.$image->marker().'` - "'.$image->name.'" ('.$this->describeOrigin($image->origin).")\n";
        }

        return "\n\n## Images available for this document\n\n"
            ."Place an image by writing its marker on its own line, copied exactly:\n\n"
            .$lines
            ."\nUse ONLY markers from this list and never invent one. If the user asks\n"
            ."for an image that is not listed, generate the document without it.\n";
    }

    /**
     * Image attachments of a message in marker order — `attached:1` is the first
     * entry. Shared with the resolver so the offered and the accepted markers
     * can never drift apart.
     *
     * @return list<File>
     */
    public function attachments(Message $message): array
    {
        $files = [];
        foreach ($message->getFiles() as $file) {
            if ($this->isSupportedImage($file)) {
                $files[] = $file;
            }
        }

        return $files;
    }

    /**
     * Absolute path of a user image inside the upload dir, or null when the
     * extension is unsupported, the file is gone, or the path escapes the root.
     */
    public function absolutePath(File $file): ?string
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

    public function isSupportedImage(File $file): bool
    {
        $extension = strtolower(pathinfo($file->getFilePath(), PATHINFO_EXTENSION));

        return in_array($extension, self::SUPPORTED_EXTENSIONS, true);
    }

    /**
     * Reduce a stored media path to the upload-dir-relative form BFILES holds:
     * node file descriptors carry the public serve URL, not the raw path.
     */
    public function normalizeRelativePath(string $path): string
    {
        if (1 === preg_match('#^https?://[^/]+(/.*)$#i', $path, $matches)) {
            $path = $matches[1];
        }

        $stripped = preg_replace('#^/?api/v1/files/uploads/#', '', $path);

        return ltrim(null === $stripped ? $path : $stripped, '/');
    }

    /**
     * @param list<DocumentImage> $images
     * @param array<int, true>    $seenIds
     * @param array<string, true> $seenPaths
     */
    private function collect(array &$images, array &$seenIds, array &$seenPaths, File $file, string $origin, ?int $attachmentIndex = null): void
    {
        $id = $file->getId();
        if (null !== $id && isset($seenIds[$id])) {
            return;
        }

        $path = $this->absolutePath($file);
        if (null === $path || isset($seenPaths[$path])) {
            return;
        }

        if (null === $id && null === $attachmentIndex) {
            return; // no stable reference the model could use
        }

        if (null !== $id) {
            $seenIds[$id] = true;
        }
        $seenPaths[$path] = true;

        $images[] = new DocumentImage(
            null !== $id ? 'file:'.$id : 'attached:'.$attachmentIndex,
            $this->displayName($file),
            $origin,
            $path,
        );
    }

    /**
     * Images of the conversation: attachments carried by the thread messages
     * plus the BFILES rows generated media links to its originating message
     * (a generated picture rides the legacy path channel, not the relation).
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return list<File>
     */
    private function threadImages(int $userId, array $thread): array
    {
        $attached = [];
        $messageIds = [];

        foreach ($thread as $entry) {
            if (!$entry instanceof Message) {
                continue; // {role, content} snapshot inside a media subprocess
            }

            $id = $entry->getId();
            if (null !== $id) {
                $messageIds[] = $id;
            }

            foreach ($entry->getFiles() as $file) {
                if ($this->isSupportedImage($file)) {
                    $attached[] = $file;
                }
            }
        }

        $linked = [] === $messageIds
            ? []
            : $this->fileRepository->findImagesByMessageIds($userId, $messageIds, self::THREAD_LOOKUP_LIMIT);

        // Newest first: the image the user just talked about wins the cap.
        $all = array_merge($attached, $linked);
        usort($all, static fn (File $a, File $b): int => ($b->getId() ?? 0) <=> ($a->getId() ?? 0));

        return $all;
    }

    /**
     * @param list<string> $paths
     *
     * @return list<File>
     */
    private function filesForPaths(int $userId, array $paths): array
    {
        $files = [];
        foreach ($paths as $path) {
            $relative = $this->normalizeRelativePath($path);
            if ('' === $relative) {
                continue;
            }

            $file = $this->fileRepository->findOneBy(['userId' => $userId, 'filePath' => $relative]);
            if ($file instanceof File) {
                $files[] = $file;
            }
        }

        return $files;
    }

    private function originOf(File $file): string
    {
        return 'generated' === $file->getSource()
            ? DocumentImage::ORIGIN_GENERATED
            : DocumentImage::ORIGIN_UPLOADED;
    }

    private function describeOrigin(string $origin): string
    {
        return match ($origin) {
            DocumentImage::ORIGIN_ATTACHED => 'attached to the current message',
            DocumentImage::ORIGIN_GENERATED => 'generated earlier in this conversation',
            default => 'shared earlier in this conversation',
        };
    }

    /**
     * Quotes and line breaks would break the single-line list entry the model
     * reads, so the name is reduced to a harmless label.
     */
    private function displayName(File $file): string
    {
        $name = $file->getOriginalName() ?? $file->getFileName();
        if ('' === trim($name)) {
            $name = basename($file->getFilePath());
        }

        $clean = preg_replace('/[\p{C}"`]+/u', '', $name) ?? $name;
        $clean = trim($clean);

        return '' === $clean ? 'image' : mb_substr($clean, 0, 80);
    }
}
