<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Message;
use App\Service\Connection\PlannerChannel;

/**
 * Detects connected-folder file-content asks and whether extracted text exists.
 */
final readonly class ConnectedFolderContentGuard
{
    private const INSTRUCTION = "\n\n**CONNECTED FOLDER READ HONESTY:** The user asked for information that should come from files in a connected folder, but this turn has no extracted text from those files. Do NOT present table rows, vendors, amounts, dates, invoice numbers, or other file-specific facts as if you read the folder. Start with one clear sentence that the connected folder was not read, so you cannot list or summarize those file contents. Prior chat and stored memories are not substitutes for the folder files.";

    public static function instructionBlock(): string
    {
        return self::INSTRUCTION;
    }

    /**
     * @param list<PlannerChannel> $folderChannels
     */
    public function demandsFolderFileContent(string $text, array $folderChannels): bool
    {
        $trimmed = trim($text);
        if ('' === $trimmed) {
            return false;
        }
        if (!self::textAsksForFileDerivedContent($trimmed)) {
            return false;
        }

        return self::textMentionsConnectedFolder($trimmed, $folderChannels);
    }

    public function hasExtractedFileTextOnMessage(Message $message): bool
    {
        foreach ($message->getFiles() as $file) {
            if ('' !== trim($file->getFileText())) {
                return true;
            }
        }

        return false;
    }

    private static function textAsksForFileDerivedContent(string $text): bool
    {
        return 1 === preg_match(
            '/\b('
            .'table|list|read|show|tell|extract|compare|vergleich|'
            .'summarize|summarise|summary|'
            .'vendor|supplier|total|amount|invoice|rechnung|'
            .'inhal|content|inside|within|'
            .'datei|files?|documents?|pdfs?'
            .')\b/iu',
            $text,
        );
    }

    /**
     * @param list<PlannerChannel> $folderChannels
     */
    private static function textMentionsConnectedFolder(string $text, array $folderChannels): bool
    {
        if (1 === preg_match(
            '/\b(nextcloud|owncloud|opencloud|connected folder|cloud folder|'
            .'verbundene[rn]?\s+ordner|mein(?:e|en|em)?\s+(?:nextcloud|cloud|ordner))\b/iu',
            $text,
        )) {
            return true;
        }

        $haystack = mb_strtolower($text);
        foreach ($folderChannels as $channel) {
            if ('' !== $channel->key && str_contains($haystack, mb_strtolower($channel->key))) {
                return true;
            }
            if ('' !== $channel->label && str_contains($haystack, mb_strtolower($channel->label))) {
                return true;
            }
        }

        return 1 === preg_match('/\b(?:in|from|aus|im|inside)\s+(?:the\s+)?(?:folder|ordner)\b/iu', $text);
    }
}
