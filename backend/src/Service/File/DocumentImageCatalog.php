<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\File;
use App\Entity\Message;

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
 *
 * Which files exist in the conversation is answered by
 * {@see ConversationFileCatalog}; this class only decides how a document turn
 * may name and place them.
 */
final readonly class DocumentImageCatalog
{
    public const SUPPORTED_EXTENSIONS = ['gif', 'jpeg', 'jpg', 'png', 'webp'];

    /** Keep the prompt block short — the newest images are the relevant ones. */
    private const MAX_IMAGES = 8;

    public function __construct(
        private ConversationFileCatalog $conversationFiles,
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
        $images = [];

        foreach ($this->conversationFiles->build($message, $thread, $extraPaths, ConversationFile::CATEGORY_IMAGE) as $file) {
            // Path-only entries (legacy generated media with no BFILES row)
            // cannot be named in a marker the resolver accepts, and offering an
            // unresolvable marker is the #1382 failure this catalog prevents.
            if (null === $file->fileId && !str_starts_with($file->reference, 'attached:')) {
                continue;
            }

            $images[] = new DocumentImage(
                $file->reference,
                $file->displayName,
                $file->origin,
                $file->absolutePath,
            );

            if (count($images) >= self::MAX_IMAGES) {
                break;
            }
        }

        return $images;
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
        return array_values(array_filter(
            $this->conversationFiles->attachments($message),
            fn (File $file): bool => $this->isSupportedImage($file),
        ));
    }

    /**
     * Absolute path of a user image inside the upload dir, or null when the
     * extension is unsupported, the file is gone, or the path escapes the root.
     */
    public function absolutePath(File $file): ?string
    {
        return $this->isSupportedImage($file) ? $this->conversationFiles->absolutePath($file) : null;
    }

    public function isSupportedImage(File $file): bool
    {
        return ConversationFile::CATEGORY_IMAGE === ConversationFile::categoryForPath($file->getFilePath());
    }

    /**
     * Reduce a stored media path to the upload-dir-relative form BFILES holds:
     * node file descriptors carry the public serve URL, not the raw path.
     */
    public function normalizeRelativePath(string $path): string
    {
        return $this->conversationFiles->normalizeRelativePath($path);
    }

    private function describeOrigin(string $origin): string
    {
        return match ($origin) {
            DocumentImage::ORIGIN_ATTACHED => 'attached to the current message',
            DocumentImage::ORIGIN_GENERATED => 'generated earlier in this conversation',
            default => 'shared earlier in this conversation',
        };
    }
}
