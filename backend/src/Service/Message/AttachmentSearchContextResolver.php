<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\AI\Service\AiFacade;
use App\Entity\Message;
use App\Service\File\FileTypeResolver;
use Psr\Log\LoggerInterface;

/**
 * Resolves WHAT an attached file is about, so the web-search query can be
 * built from the file's content instead of the user's deictic words.
 *
 * "Photo + 'how much does this cost?'" used to search for the literal words
 * "how much does this cost" — the query generator only ever saw the text.
 * This resolver supplies the missing referent:
 *
 *   1. Extracted file text first (free). MessagePreProcessor has already run:
 *      documents carry Tika text, audio/video carry transcripts, images carry
 *      an OCR pass — all on the File entities ({@see Message::getAllFilesText()}).
 *   2. Vision identification as fallback (one pic2text call). A photo of an
 *      object/landmark without visible text has an EMPTY OCR result, so the
 *      configured vision model is asked to name the subject. This is the only
 *      case that costs an extra model round-trip, and it is only paid when a
 *      search is actually about to run.
 *
 * Returns null when the message has no attachment content and no image to
 * identify (or vision failed) — the caller decides whether a text-only
 * search still makes sense (see MessageProcessor).
 */
final readonly class AttachmentSearchContextResolver
{
    /**
     * Upper bound for the context handed to the search-query model. Search
     * queries are 3–8 words; a clipped excerpt identifies the subject just as
     * well as a full document and keeps the query call cheap and fast.
     */
    private const MAX_CONTEXT_CHARS = 1500;

    /**
     * Subject identification, not scene prose: the answer is fed to a
     * search-query generator, so precise names (product, brand/model,
     * landmark, species, title) beat flowery description.
     */
    private const IDENTIFY_PROMPT = 'Identify what is shown in this image so it can be researched '
        .'in a web search. Name the main subject as precisely as possible '
        .'(product with brand and model, landmark, artwork, plant or animal '
        .'species, vehicle, dish, ...) plus any distinctive visible details. '
        .'Reply with one short phrase or sentence. No commentary.';

    public function __construct(
        private AiFacade $aiFacade,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Content of the message's attachments for search-query building, or
     * null when nothing could be resolved.
     */
    public function resolve(Message $message, ?int $userId): ?string
    {
        if (!$message->hasFiles()) {
            return null;
        }

        // 1. Already-extracted text: document body, audio/video transcript,
        //    image OCR. Covers uploads AND library files selected via fileIds
        //    (both ride on the same File relation).
        $filesText = trim($message->getAllFilesText());
        if ('' !== $filesText) {
            return $this->clip($filesText);
        }

        // 2. No text anywhere — an image of something without visible text.
        //    Ask the vision model to name the subject.
        $imagePath = $this->firstImagePath($message);
        if (null === $imagePath) {
            return null;
        }

        try {
            $result = $this->aiFacade->analyzeImage($imagePath, self::IDENTIFY_PROMPT, $userId, [
                'max_tokens' => 150,
                'temperature' => 0.1,
            ]);

            $description = trim($result['content'] ?? '');

            $this->logger->info('AttachmentSearchContextResolver: vision identification for search', [
                'message_id' => $message->getId(),
                'image' => basename($imagePath),
                'provider' => $result['provider'] ?? null,
                'description_length' => strlen($description),
            ]);

            return '' !== $description ? $this->clip($description) : null;
        } catch (\Throwable $e) {
            $this->logger->warning('AttachmentSearchContextResolver: vision identification failed', [
                'message_id' => $message->getId(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Relative path of the first attached image, or null. Checks the File
     * relation first, then the legacy single-file columns (channel messages).
     */
    private function firstImagePath(Message $message): ?string
    {
        foreach ($message->getFiles() as $file) {
            $path = $file->getFilePath();
            if ('' !== $path && 'image' === FileTypeResolver::resolveCategory($file->getFileType() ?: '', $file->getFileName(), $path)) {
                return $path;
            }
        }

        $legacyPath = (string) $message->getFilePath();
        if ($message->getFile() > 0 && '' !== $legacyPath
            && 'image' === FileTypeResolver::resolveCategory($message->getFileType() ?: '', '', $legacyPath)) {
            return $legacyPath;
        }

        return null;
    }

    private function clip(string $text): string
    {
        if (mb_strlen($text) <= self::MAX_CONTEXT_CHARS) {
            return $text;
        }

        return mb_substr($text, 0, self::MAX_CONTEXT_CHARS).'…';
    }
}
