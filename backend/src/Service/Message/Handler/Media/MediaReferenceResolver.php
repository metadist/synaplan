<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\FileHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Phase 1 of a media turn: the pictures it starts from. Attached images and
 * the reference images a multitask chain hands in (#1144), public image URLs
 * in the text or forwarded by the chain, and, when none of those exist and
 * the sorter voted for an edit, the newest picture of the conversation.
 */
final readonly class MediaReferenceResolver
{
    public function __construct(
        private ConversationFileCatalog $conversationFileCatalog,
        private LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/uploads')]
        private string $uploadDir,
    ) {
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param array<string, mixed>                                     $classification
     * @param array<string, mixed>                                     $options
     */
    public function resolve(Message $message, array $thread, array $classification, array $options): MediaReferences
    {
        $referenceImagePaths = is_array($options['reference_image_paths'] ?? null) ? $options['reference_image_paths'] : [];
        $attachedImagePaths = $this->collectAttachedImagePaths($message, $referenceImagePaths);

        // A public image URL in the text is an image-to-video reference the
        // provider can fetch as it is. The multitask runner forwards the URLs
        // of the original message, which its cleaned node prompt no longer has.
        $imageUrlsInText = FileHelper::extractImageUrls($message->getText());
        foreach ($this->referenceImageUrlsFromOptions($options) as $optionImageUrl) {
            if (!in_array($optionImageUrl, $imageUrlsInText, true)) {
                $imageUrlsInText[] = $optionImageUrl;
            }
        }

        // A cross-turn edit ("mach das Auto blau") reuses the conversation's
        // picture, only when nothing else supplied a reference.
        $editSource = null;
        if ([] === $attachedImagePaths && [] === $imageUrlsInText) {
            $editSource = $this->resolveConversationEditSource($message, $thread, $classification);
        }

        return new MediaReferences($attachedImagePaths, $imageUrlsInText, $editSource);
    }

    /**
     * Resolve the picture an attachment-less edit request refers to.
     *
     * Deliberately conservative: reusing an old image on a "draw me something
     * new" request is worse than the bug this fixes, so the edit has to be
     * voted for by the sorter (BINPUTMODE=reference_images). New-image phrasing
     * ("another one", "neues Bild") maps to text_only there and never reaches
     * this method. The target is the newest picture of the conversation — the
     * one the user was just looking at.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param array<string, mixed>                                     $classification
     */
    private function resolveConversationEditSource(
        Message $message,
        array $thread,
        array $classification,
    ): ?ConversationFile {
        if ('reference_images' !== ($classification['input_mode'] ?? null)) {
            return null;
        }

        // A video/audio request must never pick up an old picture: that would
        // silently turn text-to-video into image-to-video.
        $requestedMediaType = $classification['media_type'] ?? null;
        if (null !== $requestedMediaType && 'image' !== $requestedMediaType) {
            return null;
        }

        // `/vid` and `/tts` say the same thing, but the sorter does not always
        // fill media_type for a slash command — the topic is the reliable
        // signal there.
        if (in_array($classification['topic'] ?? null, ['tools:vid', 'tools:tts'], true)) {
            return null;
        }

        $catalog = $this->conversationFileCatalog->build($message, $thread, [], ConversationFile::CATEGORY_IMAGE);

        return $this->conversationFileCatalog->latestImage($catalog);
    }

    /**
     * Collect absolute paths of image attachments from the message.
     *
     * @param list<string> $extraImagePaths reference images supplied by the caller
     *                                      (issue #1144) — relative to the upload
     *                                      dir, an absolute path, or a public
     *                                      `/api/v1/files/uploads/...` display URL
     *
     * @return list<string> absolute, on-disk image paths to attached images
     */
    private function collectAttachedImagePaths(Message $message, array $extraImagePaths = []): array
    {
        $paths = [];
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

        foreach ($message->getFiles() as $file) {
            $ext = strtolower(pathinfo($file->getFilePath(), PATHINFO_EXTENSION));
            if (in_array($ext, $imageExtensions, true)) {
                $absolutePath = $this->uploadDir.'/'.$file->getFilePath();
                if (file_exists($absolutePath)) {
                    $paths[] = $absolutePath;
                }
            }
        }

        if (empty($paths)) {
            $legacyPath = $message->getFilePath();
            if ($legacyPath) {
                $ext = strtolower(pathinfo($legacyPath, PATHINFO_EXTENSION));
                if (in_array($ext, $imageExtensions, true)) {
                    $absolutePath = $this->uploadDir.'/'.$legacyPath;
                    if (file_exists($absolutePath)) {
                        $paths[] = $absolutePath;
                    }
                }
            }
        }

        // Merge caller-supplied reference images (multitask media-to-media chains).
        // Each candidate is normalised to an absolute on-disk path and filtered by
        // image extension + existence, exactly like the message's own uploads.
        foreach ($extraImagePaths as $candidate) {
            if ('' === $candidate) {
                continue;
            }
            $absolutePath = $this->normalizeReferenceImagePath($candidate);
            if (null === $absolutePath) {
                continue;
            }
            $ext = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));
            if (in_array($ext, $imageExtensions, true) && !in_array($absolutePath, $paths, true)) {
                $paths[] = $absolutePath;
            }
        }

        return $paths;
    }

    /**
     * Normalise caller-supplied reference image URLs (multitask path: the URL
     * lives in the original user message, not the synthetic node prompt). Each
     * candidate is run through the same allowlist as
     * {@see FileHelper::extractImageUrls()}, so only http(s) URLs ending in a
     * known image extension survive — these values are handed to providers as
     * `image_url`/`images`, so a non-image / non-http(s) target must never pass.
     * De-duplicated in first-seen order.
     *
     * @param array<string, mixed> $options
     *
     * @return list<string>
     */
    private function referenceImageUrlsFromOptions(array $options): array
    {
        $candidates = $options['reference_image_urls'] ?? null;
        if (!is_array($candidates)) {
            return [];
        }

        $urls = [];
        foreach ($candidates as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            foreach (FileHelper::extractImageUrls($candidate) as $url) {
                if (!in_array($url, $urls, true)) {
                    $urls[] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * Resolve a caller-supplied reference image reference to an absolute on-disk
     * path (issue #1144). Accepts an absolute path, a path relative to the upload
     * dir, or a public `/api/v1/files/uploads/...` display URL. Returns null when
     * the file cannot be found.
     */
    private function normalizeReferenceImagePath(string $candidate): ?string
    {
        // Strip the public display prefix so a `$nX.file` `path` resolves to the
        // same relative key the upload dir uses.
        $relative = preg_replace('#^/?api/v1/files/uploads/#', '', $candidate);
        $relative = null === $relative ? $candidate : $relative;

        // Already an absolute, existing path.
        if (str_starts_with($candidate, '/') && file_exists($candidate)) {
            return $candidate;
        }

        $absolute = $this->uploadDir.'/'.ltrim($relative, '/');
        if (file_exists($absolute)) {
            return $absolute;
        }

        $this->logger->warning('MediaGenerationHandler: reference image not found on disk', [
            'candidate' => $candidate,
        ]);

        return null;
    }
}
