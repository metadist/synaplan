<?php

namespace App\Service\Message\Classification;

use App\Entity\Message;
use App\Service\File\ConversationFile;

/**
 * What the previous turns of a conversation produced: whether the latest or
 * any assistant turn made a document or a picture, and whether a message
 * refers to a document or a picture. A follow-up on generated work is
 * usually an edit of it, so it must reach the AI sorter.
 */
final class ThreadArtifacts
{
    /**
     * Whether the most recent assistant turn passes $check. Only the latest
     * assistant message counts, so a deferral is limited to the turn directly
     * following the generation.
     *
     * @param array<int, Message>     $conversationHistory oldest-first thread
     * @param callable(Message): bool $check
     */
    public static function lastAssistantTurnIs(array $conversationHistory, callable $check): bool
    {
        for ($i = count($conversationHistory) - 1; $i >= 0; --$i) {
            if ('OUT' === $conversationHistory[$i]->getDirection()) {
                return $check($conversationHistory[$i]);
            }
        }

        return false;
    }

    /**
     * @param array<int, Message>     $conversationHistory
     * @param callable(Message): bool $check
     */
    public static function anyAssistantTurnIs(array $conversationHistory, callable $check): bool
    {
        foreach ($conversationHistory as $msg) {
            if ('OUT' === $msg->getDirection() && $check($msg)) {
                return true;
            }
        }

        return false;
    }

    /** An office document: its stored content is the "__FILE_GENERATED__:filename" marker. */
    public static function isGeneratedFileMessage(Message $message): bool
    {
        return str_starts_with((string) $message->getText(), '__FILE_GENERATED__:');
    }

    /**
     * A picture or a video. Generated media rides the message's own file path
     * (BFILEPATH/BFILETYPE), not the `__FILE_GENERATED__:` marker.
     */
    public static function isGeneratedMediaMessage(Message $message): bool
    {
        $path = $message->getFilePath();
        if ('' === $path) {
            return false;
        }

        $category = ConversationFile::categoryForPath($path);

        return ConversationFile::CATEGORY_IMAGE === $category
            || ConversationFile::CATEGORY_VIDEO === $category;
    }

    /**
     * Whether the message refers to a picture or one of its visual properties.
     *
     * The media sibling of {@see mentionsDocumentReference()}, used together
     * with {@see isGeneratedMediaMessage()} earlier in the thread to catch edits that span several
     * turns ("und jetzt die Farbe ändern"). Kept deliberately visual so normal
     * chat rarely matches; a false positive costs one extra AI-sorter call.
     */
    public static function mentionsMediaReference(string $text): bool
    {
        return 1 === preg_match(
            '/\b(bild|bildes|bilder|foto|fotos|grafik|logo|image|picture|photo|imagen|imagem|immagine|resim|görsel|'
            .'farbe|farben|color|colour|colore|renk|hintergrund|background|fondo|stil|style|estilo|'
            .'auflösung|aufloesung|resolution|zuschneiden|crop|retusch\w*|retouch\w*)\b/iu',
            $text
        );
    }

    /**
     * Whether the message refers to a document or one of its structural parts.
     *
     * Used together with {@see isGeneratedFileMessage()} earlier in the thread to detect document
     * edits that span multiple turns. The noun list is intentionally
     * document-specific to keep false positives in normal chat low; the worst
     * case of a false positive is one extra AI-sorter call.
     */
    public static function mentionsDocumentReference(string $text): bool
    {
        return 1 === preg_match(
            '/\b(datei|dokument|file|document|doc|tabelle|sheet|spreadsheet|folie|slide|'
            .'titel|title|überschrift|ueberschrift|heading|spalte|column|zeile|row|zelle|cell)\b/iu',
            $text
        );
    }
}
