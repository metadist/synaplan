<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\File;
use App\Entity\Message;
use App\Entity\Model;
use App\Service\File\ConversationFile;
use App\Service\File\ConversationFileCatalog;

/**
 * How earlier turns of a thread appear to the model: file markers in
 * plain words, a note on the media an assistant turn delivered, and
 * which empty assistant turns are left out.
 *
 * The message builders and the digest search share these rules, so a
 * message that is replayed verbatim is never also cited as a digest.
 */
final readonly class ConversationHistoryRenderer
{
    public function __construct(
        private ConversationFileCatalog $conversationFileCatalog,
    ) {
    }

    /**
     * Always-on prose references for media the assistant produced in earlier
     * turns of this conversation.
     *
     * Generated images, videos and audio carry no BFILETEXT, so replaying the
     * thread as plain text leaves no trace of them: a follow-up such as "what is
     * in that picture?" is then answered from text-only history and the model denies
     * the media exists (#1596). Sending the actual pixels is a separate path
     * ({@see \App\Service\Vision\VisionAttachmentEncoder::generatedImagesForVision()}); this cheap text reference is
     * unconditional so the model still knows the media is real when the
     * kill-switch is off or the chat model cannot see images. It also gives
     * async media turns whose text was cleared
     * ({@see \App\Service\Media\MediaJobMessageSync}) non-empty content, so they
     * survive the empty-assistant filter (#1115) instead of vanishing entirely.
     *
     * Documents are intentionally excluded — they already ride along as
     * extracted text / the __FILE_GENERATED__ marker.
     *
     * @param array<int, Message|array{role: string, content: string}> $thread
     *
     * @return array<int, string> assistant messageId => reference sentence
     */
    public function mediaReferences(Message $currentMessage, array $thread): array
    {
        $catalog = $this->conversationFileCatalog->build($currentMessage, $thread);

        $labelsByMessage = [];
        foreach ($catalog as $file) {
            if (!$file->isGenerated() || null === $file->messageId) {
                continue;
            }

            $noun = match ($file->category) {
                ConversationFile::CATEGORY_IMAGE => 'an image',
                ConversationFile::CATEGORY_VIDEO => 'a video',
                ConversationFile::CATEGORY_AUDIO => 'an audio file',
                default => null, // documents already have a textual trace
            };
            if (null === $noun) {
                continue;
            }

            $labelsByMessage[$file->messageId][] = $noun.' ("'.$file->displayName.'")';
        }

        $references = [];
        foreach ($labelsByMessage as $messageId => $labels) {
            $labels = array_slice($labels, 0, 4);
            $references[$messageId] = '(For reference: earlier in this conversation you generated and delivered the following media to the user, which is shown in the chat: '
                .implode(', ', $labels).'. These files exist — do not claim otherwise.)';
        }

        return $references;
    }

    /**
     * True when an assistant history turn has no usable text (and no multimodal
     * parts). Used to keep empty OUT rows out of provider payloads (#1115).
     *
     * @param string|array<int, mixed> $content
     */
    public function isEmptyAssistantContent(string|array $content): bool
    {
        if (is_string($content)) {
            // Older prompts prefixed "[datetime]: " and models echoed it back.
            // A stamp with no body is still an empty turn.
            $stripped = preg_replace('/^\[[^\]]*\]:\s*/u', '', $content) ?? $content;

            return '' === trim($stripped);
        }

        foreach ($content as $part) {
            if (!is_array($part)) {
                continue;
            }
            $type = $part['type'] ?? '';
            if ('text' === $type && '' !== trim((string) ($part['text'] ?? ''))) {
                return false;
            }
            if (in_array($type, ['image_url', 'image', 'input_image'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $mediaReferences
     */
    private function isOmittedAssistantTurn(Message $msg, array $mediaReferences, bool $promptIncludesFileText): bool
    {
        if ('IN' === $msg->getDirection()) {
            return false;
        }

        $content = $this->humanizeFileMarkers($msg->getText());
        $files = $promptIncludesFileText ? $msg->getAllFilesText() : '';
        if ('' !== $files) {
            $content .= "\n".$files;
        }
        $id = $msg->getId();
        if (null !== $id && isset($mediaReferences[$id])) {
            $reference = $mediaReferences[$id];
            $content = '' === trim($content) ? $reference : $content."\n".$reference;
        }

        return $this->isEmptyAssistantContent($content);
    }

    /**
     * Message ids replayed verbatim. `$thread` is already the history window
     * (message and character caps, and only the recent tail when a rolling
     * summary replaced the older turns). Empty assistant turns are omitted by
     * the same rule as the message builders: only the streaming builder adds
     * file text, so `$promptIncludesFileText` must match the path. The current
     * message is included.
     *
     * @param array<int, array{role: string, content: string}|Message> $thread
     *
     * @return list<int>
     */
    public function verbatimMessageIds(array $thread, Message $current, bool $promptIncludesFileText): array
    {
        $mediaReferences = $this->mediaReferences($current, $thread);
        $ids = [];
        $currentId = $current->getId();

        foreach ($thread as $msg) {
            if (!$msg instanceof Message) {
                continue;
            }
            $id = $msg->getId();
            if (null === $id || $id === $currentId) {
                continue;
            }
            if ($this->isOmittedAssistantTurn($msg, $mediaReferences, $promptIncludesFileText)) {
                continue;
            }
            $ids[] = $id;
        }

        if (null !== $currentId) {
            $ids[] = $currentId;
        }

        return $ids;
    }

    /**
     * Replace internal generated-media markers with plain prose before a prior
     * assistant turn is sent back to the model as conversation history.
     *
     * @see GeneratedMediaTextRenderer::forModel()
     */
    public function humanizeFileMarkers(?string $content): string
    {
        return GeneratedMediaTextRenderer::renderModel((string) $content);
    }
}
