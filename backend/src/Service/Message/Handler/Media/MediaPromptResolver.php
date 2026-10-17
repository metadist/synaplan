<?php

namespace App\Service\Message\Handler\Media;

use App\Entity\Message;
use App\Service\Message\MediaPromptExtractor;
use App\Service\Message\ProgressCallback;
use Psr\Log\LoggerInterface;

/**
 * Phase 2 of a media turn: the prompt. The mediamaker extraction writes it,
 * told which picture an edit changes, and has the final say on the media
 * type: a video or audio request drops the conversation's picture and is
 * extracted again without the edit wording. The message text stands in for
 * an empty prompt.
 */
final readonly class MediaPromptResolver
{
    public function __construct(
        private MediaPromptExtractor $promptExtractor,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     * @param array<string, mixed>                                     $classification
     */
    public function resolve(Message $message, array $thread, array $classification, MediaReferences $references, ?callable $progressCallback): MediaPrompt
    {
        $editSource = $references->editSource;
        $promptData = $this->promptExtractor->extract(
            $message,
            $thread,
            $classification,
            null !== $editSource ? ['media_edit_source_name' => $editSource->displayName] : [],
        );
        $promptMediaType = $promptData['media_type'] ?? null;

        // A video or audio request must not animate an unrelated old picture,
        // and its prompt was written as "describe only the change".
        if (null !== $editSource && null !== $promptMediaType && 'image' !== $promptMediaType) {
            $this->logger->info('MediaGenerationHandler: dropping conversation edit source, extractor asked for a different media type', [
                'media_type' => $promptMediaType,
                'edit_source' => $editSource->reference,
            ]);
            $editSource = null;
            $promptData = $this->promptExtractor->extract($message, $thread, $classification);
            $promptMediaType = $promptData['media_type'] ?? null;
        }

        $prompt = trim($promptData['prompt']);

        if ('' === $prompt) {
            $prompt = $message->getText();
        }

        if ('' === $prompt) {
            throw new \RuntimeException('Unable to determine media prompt text');
        }

        $attachedImagePaths = $references->attachedImagePaths;
        if (null !== $editSource) {
            $attachedImagePaths[] = $editSource->absolutePath;
            ProgressCallback::notify($progressCallback, 'editing', 'Editing '.$editSource->displayName.'…', [
                'edit_source_file_id' => $editSource->fileId,
                'edit_source_name' => $editSource->displayName,
            ]);
        }

        $this->logger->info('MediaGenerationHandler: Starting media generation', [
            'user_id' => $message->getUserId(),
            'prompt' => substr($prompt, 0, 100),
            'media_hint' => $promptMediaType,
            'is_pic2pic' => [] !== $attachedImagePaths,
            'attached_images' => count($attachedImagePaths),
            'image_urls_in_text' => count($references->imageUrlsInText),
            'edit_source' => $editSource?->reference,
        ]);

        return new MediaPrompt($prompt, $promptMediaType, new MediaReferences($attachedImagePaths, $references->imageUrlsInText, $editSource));
    }
}
