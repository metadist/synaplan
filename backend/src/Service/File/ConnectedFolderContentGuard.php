<?php

declare(strict_types=1);

namespace App\Service\File;

use App\Entity\Message;
use App\Service\Connection\PlannerChannel;
use App\Service\Multitask\Execution\NodeContext;
use App\Service\Multitask\Execution\StepFiles;
use App\Service\Multitask\Plan\TaskNode;

/**
 * Stops chat answers from presenting connected-folder file content as read
 * when this turn never loaded file bodies (#2667).
 */
final readonly class ConnectedFolderContentGuard
{
    private const INSTRUCTION = "\n\n**CONNECTED FOLDER READ HONESTY:** The user asked for information that should come from files in a connected folder, but this turn has no extracted text from those files. Do NOT present table rows, vendors, amounts, dates, invoice numbers, or other file-specific facts as if you read the folder. Start with one clear sentence that the connected folder was not read, so you cannot list or summarize those file contents. Prior chat and stored memories are not substitutes for the folder files.";

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

    /**
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    public function hasExtractedFileText(Message $message, array $thread): bool
    {
        foreach ([$message, ...$thread] as $entry) {
            if (!$entry instanceof Message) {
                continue;
            }
            foreach ($entry->getFiles() as $file) {
                if ('' !== trim($file->getFileText())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $inputs
     */
    public function hasUpstreamFolderFileContent(
        TaskNode $node,
        NodeContext $context,
        array $inputs,
        string $assembledUserText,
    ): bool {
        if ('' !== trim($assembledUserText) && mb_strlen(trim($assembledUserText)) >= 400) {
            return true;
        }

        foreach ($node->dependsOn as $dependencyId) {
            $result = $context->getResult($dependencyId);
            if (null !== $result && $result->isSuccessful() && mb_strlen(trim($result->text)) >= 400) {
                return true;
            }
        }

        return [] !== StepFiles::of($node, $context, $inputs);
    }

    /**
     * @param list<PlannerChannel>                                     $folderChannels
     * @param array<int, Message|array{role: string, content: string}> $thread
     */
    public function systemInstructionForChatHandler(
        string $userText,
        array $folderChannels,
        Message $message,
        array $thread,
    ): string {
        if ([] === $folderChannels || !$this->demandsFolderFileContent($userText, $folderChannels)) {
            return '';
        }
        if ($this->hasExtractedFileText($message, $thread)) {
            return '';
        }

        return self::INSTRUCTION;
    }

    /**
     * @param list<PlannerChannel> $folderChannels
     * @param array<string, mixed> $inputs
     */
    public function systemInstructionForChatRunner(
        string $userText,
        array $folderChannels,
        Message $message,
        TaskNode $node,
        NodeContext $context,
        array $inputs,
        string $assembledUserText,
    ): string {
        if ([] === $folderChannels || !$this->demandsFolderFileContent($userText, $folderChannels)) {
            return '';
        }
        if ($this->hasExtractedFileText($message, [])) {
            return '';
        }
        if ($this->hasUpstreamFolderFileContent($node, $context, $inputs, $assembledUserText)) {
            return '';
        }

        return self::INSTRUCTION;
    }

    private static function textAsksForFileDerivedContent(string $text): bool
    {
        return 1 === preg_match(
            '/\b('
            .'table|list|summar|read|show|tell|extract|compare|vergleich|'
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
