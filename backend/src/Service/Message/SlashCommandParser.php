<?php

declare(strict_types=1);

namespace App\Service\Message;

/**
 * Splits a leading slash command from its argument.
 *
 * Handles Telegram's `/command@BotName` form and treats whitespace-only
 * arguments as bare. Used by {@see MessageClassifier} so every channel that
 * reaches MessageProcessor shares one rule set.
 */
final class SlashCommandParser
{
    /**
     * @return SlashCommand|null null when the text is not a slash command
     */
    public function parse(string $text): ?SlashCommand
    {
        $trimmed = trim($text);
        if ('' === $trimmed || !str_starts_with($trimmed, '/')) {
            return null;
        }

        if (!preg_match('/^\/([A-Za-z]+)(?:@[A-Za-z0-9_]+)?(?:\s+(.*))?$/s', $trimmed, $matches)) {
            return null;
        }

        return new SlashCommand(
            strtolower($matches[1]),
            trim($matches[2] ?? ''),
        );
    }
}
