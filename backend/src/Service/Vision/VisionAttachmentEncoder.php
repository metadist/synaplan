<?php

declare(strict_types=1);

namespace App\Service\Vision;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Entity\User;
use App\Repository\ModelRepository;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;
use App\Service\File\FileHelper;
use App\Service\File\GeneratedImageVisionFlag;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Images for a vision model: the pictures attached to a turn, the ones
 * the assistant generated earlier in the conversation, and the model
 * that can look at them when the chat model cannot.
 *
 * Every image is read from the upload root, downscaled to the inline
 * budget, and sent as a base64 data URL.
 */
final readonly class VisionAttachmentEncoder
{
    /**
     * Maximum base64 payload length (characters) for a single inline vision
     * image. Kept well under Anthropic's 1M-token context limit — base64
     * tokenizes at roughly 1.7 tokens per character on that provider, so
     * ~450K characters (~337 KB raw) stays comfortably below the ceiling with
     * margin for the surrounding prompt (issue #1238).
     */
    private const MAX_VISION_BASE64_LENGTH = 450000;

    /**
     * Hard upper bound on the raw file size read for inline vision. Oversized
     * files below this cap are downscaled to the base64 budget; above it we
     * refuse to read the bytes at all. Deliberately generous — phone photos
     * are routinely 10-20 MB and must survive this guard.
     */
    private const MAX_VISION_FILE_BYTES = 50 * 1024 * 1024;

    public function __construct(
        #[Autowire(param: 'app.upload_dir')] private string $uploadDir,
        private ConversationFileCatalog $conversationFileCatalog,
        private GeneratedImageVisionFlag $generatedImageVisionFlag,
        private ModelRepository $modelRepository,
        private VisionModelResolver $visionModelResolver,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Check if a message has attached images.
     *
     * @return bool True if message has vision-supported images
     */
    public function hasAttachedImages(Message $message): bool
    {
        // Check new-style file attachments (File entities)
        foreach ($message->getFiles() as $file) {
            if ($this->isVisionSupportedImage($file->getFilePath())) {
                return true;
            }
        }

        // Check legacy file path
        $legacyPath = $message->getFilePath();
        if ($legacyPath && $this->isVisionSupportedImage($legacyPath)) {
            return true;
        }

        return false;
    }

    /**
     * Extract image data URLs from a message's attached files.
     *
     * @return array<string> Array of base64 data URLs
     */
    public function imageDataUrls(Message $message): array
    {
        $imageUrls = [];

        // Check new-style file attachments (File entities)
        foreach ($message->getFiles() as $file) {
            $filePath = $file->getFilePath();
            if ($this->isVisionSupportedImage($filePath)) {
                $dataUrl = $this->toBase64DataUrl($filePath);
                if ($dataUrl) {
                    $imageUrls[] = $dataUrl;
                }
            }
        }

        // Check legacy file path
        $legacyPath = $message->getFilePath();
        if ($legacyPath && $this->isVisionSupportedImage($legacyPath)) {
            $dataUrl = $this->toBase64DataUrl($legacyPath);
            if ($dataUrl) {
                $imageUrls[] = $dataUrl;
            }
        }

        return $imageUrls;
    }

    /**
     * Check if a file is an image that can be sent to vision models.
     */
    private function isVisionSupportedImage(string $path): bool
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        // Only these formats are widely supported by vision APIs
        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp']);
    }

    /**
     * Get MIME type for an image file.
     */
    private function getImageMimeType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            default => 'image/jpeg',
        };
    }

    /**
     * Convert an image file to base64 data URL for vision APIs.
     *
     * @return string|null Base64 data URL or null if conversion fails
     */
    public function toBase64DataUrl(string $relativePath): ?string
    {
        // Security: Validate path to prevent directory traversal attacks.
        // Display URLs (`/api/v1/files/uploads/<rel>`) must be reduced to the
        // upload-dir-relative form before we prefix $uploadDir — otherwise a
        // stored serve path never resolves (#1596).
        $sanitizedPath = FileHelper::normalizeUploadRelativePath($relativePath);
        $absolutePath = $this->uploadDir.'/'.$sanitizedPath;

        // Use FileHelper to safely resolve and validate path within upload directory
        $resolvedPath = FileHelper::resolvePathNfs($absolutePath, $this->uploadDir);

        if (false === $resolvedPath) {
            $this->logger->warning('ChatHandler: Image file not found or path invalid for vision', [
                'path' => $relativePath,
                'resolved' => $absolutePath,
            ]);

            return null;
        }

        $absolutePath = $resolvedPath;

        // Sanity cap only — anything below it is read and downscaled to fit the
        // inline budget further down. The old 10 MB cutoff silently dropped
        // ordinary phone photos (routinely 10-20 MB) BEFORE the downscaler ever
        // ran, so the model answered without seeing the image.
        $fileSize = filesize($absolutePath);
        if ($fileSize > self::MAX_VISION_FILE_BYTES) {
            $this->logger->warning('ChatHandler: Image too large for vision API', [
                'path' => $relativePath,
                'size_mb' => round($fileSize / 1024 / 1024, 2),
            ]);

            return null;
        }

        $imageData = file_get_contents($absolutePath);
        if (false === $imageData) {
            $this->logger->error('ChatHandler: Failed to read image file', [
                'path' => $relativePath,
            ]);

            return null;
        }

        $mimeType = $this->getImageMimeType($relativePath);

        // Guard against oversized inline vision payloads. A large image
        // embedded as a base64 data URL can blow the provider's context
        // window: an ~828 KB generated PNG expands to ~1.1M base64 characters,
        // which Anthropic tokenizes to ~1.9M tokens — over its 1M limit — and
        // returns an HTTP 400 that surfaces as an error bubble (issue #1238).
        // The 10 MB file-size cap above does not prevent this, so downscale
        // the pixels first and only skip the image when it still cannot be
        // brought under budget.
        if ($this->estimateBase64Length(strlen($imageData)) > self::MAX_VISION_BASE64_LENGTH) {
            $downscaled = $this->downscaleImageForVision($imageData);
            if (null === $downscaled) {
                $this->logger->warning('ChatHandler: Skipping vision image that exceeds the inline payload budget', [
                    'path' => $relativePath,
                    'original_size_kb' => round(strlen($imageData) / 1024, 1),
                ]);

                return null;
            }

            $this->logger->info('ChatHandler: Downscaled oversized vision image', [
                'path' => $relativePath,
                'original_size_kb' => round(strlen($imageData) / 1024, 1),
                'downscaled_size_kb' => round(strlen($downscaled) / 1024, 1),
            ]);

            $imageData = $downscaled;
            $mimeType = 'image/jpeg';
        }

        $base64 = base64_encode($imageData);

        return 'data:'.$mimeType.';base64,'.$base64;
    }

    /**
     * Estimate the character length of the base64 encoding of a byte string
     * without allocating the encoded string (base64 emits 4 characters per 3
     * input bytes, padded).
     */
    private function estimateBase64Length(int $bytes): int
    {
        return intdiv($bytes + 2, 3) * 4;
    }

    /**
     * Downscale an oversized image so its base64 payload fits within the
     * inline-vision budget (issue #1238).
     *
     * Re-encodes as JPEG — smaller than PNG for photos and generated renders —
     * via Imagick, progressively reducing the longest edge until the encoded
     * size is under budget. 1568 px is Anthropic's own recommended vision cap;
     * the smaller steps are the fallback for very detailed images that still
     * exceed the budget at higher resolutions.
     *
     * @return string|null JPEG bytes under budget, or null when Imagick is
     *                     unavailable or the image cannot be reduced enough
     */
    private function downscaleImageForVision(string $imageData): ?string
    {
        if (!extension_loaded('imagick') || !class_exists(\Imagick::class)) {
            return null;
        }

        foreach ([1568, 1024, 768, 512] as $maxEdge) {
            try {
                $imagick = new \Imagick();
                $imagick->readImageBlob($imageData);
                $imagick->setIteratorIndex(0);
                $imagick->autoOrient();
                // bestfit: scale down so neither edge exceeds $maxEdge while
                // preserving aspect ratio.
                $imagick->resizeImage($maxEdge, $maxEdge, \Imagick::FILTER_LANCZOS, 1, true);
                $imagick->setImageFormat('jpeg');
                $imagick->setImageCompressionQuality(80);
                $imagick->stripImage();
                $jpeg = $imagick->getImageBlob();
                $imagick->clear();
                $imagick->destroy();

                if ('' !== $jpeg && $this->estimateBase64Length(strlen($jpeg)) <= self::MAX_VISION_BASE64_LENGTH) {
                    return $jpeg;
                }
            } catch (\Throwable $e) {
                $this->logger->warning('ChatHandler: Vision image downscale attempt failed', [
                    'max_edge' => $maxEdge,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }
        }

        return null;
    }

    /**
     * Base64 data URLs for images the ASSISTANT generated earlier in this
     * conversation, plus the sentence that tells the model where they came from.
     *
     * The pixels ride on the current USER turn — Anthropic (and most providers)
     * reject `image` blocks on assistant turns, which is what surfaced as "I
     * cannot see the picture" after the flag was turned on. That placement makes
     * the picture look like a fresh upload, so `notice` names the file and its
     * provenance; without it the model answers about "the image you sent me"
     * and, on an unrelated follow-up, may describe a picture nobody asked about.
     *
     * Without this path, "draw a cat" → "what is in it?" is answered from the
     * text prompt alone (#1596). On when FILE_CONTEXT.VISION_INCLUDE_GENERATED
     * is enabled (the default) and the chosen model is vision-capable, and
     * capped at MAX_GENERATED_IMAGES so a single inline image stays under the
     * 450K-character vision budget.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return array{urls: list<string>, notice: string}
     */
    public function generatedImagesForVision(Message $currentMessage, array $thread, array $options): array
    {
        if (true !== ($options['include_generated_images'] ?? false)) {
            return ['urls' => [], 'notice' => ''];
        }

        // An image the user attached to THIS turn is what they are asking
        // about. Adding a historic picture on top would send two inline
        // payloads — MAX_VISION_BASE64_LENGTH is enforced per image, so the
        // request would carry twice the intended budget — and leave the model
        // guessing which of the two the question refers to.
        if ($this->hasAttachedImages($currentMessage)) {
            return ['urls' => [], 'notice' => ''];
        }

        // Same for a document the user handed over: a .docx attached now (or
        // uploaded after the picture was drawn) is what this turn is about.
        // Inlining the historic image next to it made the model describe the
        // picture as "the attached file" and rewrite the upload around it (#1689).
        $documentInFocus = $this->documentInFocus($currentMessage, $thread);
        if (null !== $documentInFocus) {
            $this->logger->info('ChatHandler: Skipping generated images for vision, document in focus', [
                'message_id' => $currentMessage->getId(),
                'document' => $documentInFocus->reference,
            ]);

            return ['urls' => [], 'notice' => ''];
        }

        $catalog = $this->conversationFileCatalog->build(
            $currentMessage,
            $thread,
            [],
            ConversationFile::CATEGORY_IMAGE,
        );

        $urls = [];
        $names = [];

        foreach ($catalog as $file) {
            if (count($urls) >= GeneratedImageVisionFlag::MAX_GENERATED_IMAGES) {
                break;
            }
            if (!$file->isGenerated()) {
                continue;
            }
            if (!$this->isVisionSupportedImage($file->relativePath)) {
                continue;
            }

            $dataUrl = $this->toBase64DataUrl($file->relativePath);
            if (null === $dataUrl) {
                continue;
            }

            $urls[] = $dataUrl;
            $names[] = $file->displayName;
        }

        if ([] === $urls) {
            return ['urls' => [], 'notice' => ''];
        }

        $this->logger->info('ChatHandler: Including generated images for vision', [
            'message_id' => $currentMessage->getId(),
            'image_count' => count($urls),
        ]);

        return [
            'urls' => $urls,
            'notice' => $this->generatedImageProvenanceNotice($names),
        ];
    }

    /**
     * Tell the model that the image blocks on this user turn are its own
     * earlier output rather than something the user just uploaded.
     *
     * @param list<string> $displayNames
     */
    private function generatedImageProvenanceNotice(array $displayNames): string
    {
        $quoted = implode(', ', array_map(static fn (string $name): string => '"'.$name.'"', $displayNames));

        if (1 === count($displayNames)) {
            return '(The image attached to this message is not a new upload from the user — it is '
                .$quoted.', which YOU generated earlier in this conversation. Treat it as your own earlier output when answering.)';
        }

        return '(The images attached to this message are not new uploads from the user — they are '
            .$quoted.', which YOU generated earlier in this conversation. Treat them as your own earlier output when answering.)';
    }

    /**
     * Whether generated images may be shown to the model: the operator flag is
     * on and the model that will actually answer can read images.
     *
     * A chat model without vision is NOT swapped here. The vision fallback
     * only fires when the current message itself carries an image attachment;
     * a generated picture sitting in history is not enough to change the
     * user's chosen model. In that case the always-on prose reference from
     * {@see \App\Service\Message\Chat\ConversationHistoryRenderer::mediaReferences()} is the only trace the model gets.
     */
    public function shouldIncludeGeneratedImages(?int $modelId, ?int $effectiveUserId): bool
    {
        if (!$this->generatedImageVisionFlag->isEnabled($effectiveUserId)) {
            return false;
        }

        $model = $modelId ? $this->modelRepository->find($modelId) : null;

        return null !== $model && $model->hasFeature('vision');
    }

    /**
     * Resolve the vision-capable model to use when the active chat model cannot
     * see images. Delegates to {@see VisionModelResolver} so ChatHandler and the
     * Messages gateway share the same PIC2TEXT → catalog fallback.
     */
    public function fallbackModel(?int $effectiveUserId): ?Model
    {
        return $this->visionModelResolver->resolve($effectiveUserId);
    }

    /**
     * The uploaded document this turn is about, if it displaces the images the
     * assistant generated earlier ({@see ConversationFileCatalog::documentInFocus()}).
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    private function documentInFocus(Message $currentMessage, array $thread): ?ConversationFile
    {
        return $this->conversationFileCatalog->documentInFocus(
            $this->conversationFileCatalog->build($currentMessage, $thread),
        );
    }
}
