<?php

declare(strict_types=1);

namespace App\Service\Message;

use App\Entity\File;
use App\Entity\Message;
use App\Service\File\FileTypeResolver;

/**
 * A voice note whose transcript replaced an empty or placeholder message
 * is the user's words, not a file to analyze.
 *
 * The mark is set only in that replacement branch. A caption, or an audio
 * file sent as a document, never receives it.
 */
final class SpokenInput
{
    public const META_KEY = 'text_source';
    public const META_TRANSCRIPT = 'transcript';

    public static function mark(Message $message): void
    {
        $message->setMeta(self::META_KEY, self::META_TRANSCRIPT);
    }

    public static function isMarked(Message $message): bool
    {
        return self::META_TRANSCRIPT === $message->getMeta(self::META_KEY);
    }

    /**
     * Empty text and the audio placeholders channels store before speech-to-text.
     */
    public static function isReplaceablePlaceholder(string $text): bool
    {
        return '' === $text || '[Audio message]' === $text || '[Audio]' === $text;
    }

    /**
     * Replace empty or audio-placeholder text with the transcript and mark
     * the message. Any other text (a caption) is left as the user wrote it.
     */
    public static function applyTranscript(Message $message, string $transcript): void
    {
        if (!self::isReplaceablePlaceholder($message->getText())) {
            return;
        }

        $message->setText($transcript);
        self::mark($message);
    }

    /**
     * Audio whose stored transcript is the message text. Other attachments
     * on the same turn stay analyzable.
     */
    public static function isSpokenAudio(Message $message, File $file): bool
    {
        if (!self::isMarked($message)) {
            return false;
        }

        $category = FileTypeResolver::resolveCategory($file->getFileType() ?: '', $file->getFileName());
        if ('audio' !== $category) {
            return false;
        }

        $transcript = trim($file->getFileText());
        $text = trim($message->getText());

        return '' !== $text && $transcript === $text;
    }

    /**
     * Legacy single-file messages store the transcript on the message itself.
     */
    public static function isLegacySpokenAudio(Message $message): bool
    {
        if (!self::isMarked($message) || $message->getFile() <= 0 || '' === $message->getFilePath()) {
            return false;
        }

        $category = FileTypeResolver::resolveCategory(
            $message->getFileType() ?: '',
            '',
            $message->getFilePath(),
        );
        if ('audio' !== $category) {
            return false;
        }

        $transcript = trim($message->getFileText());
        $text = trim($message->getText());

        return '' !== $text && $transcript === $text;
    }
}
