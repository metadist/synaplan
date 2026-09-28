<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Claude Code wraps a person's request in reminder text and also sends
 * its own side requests (a title, a suggestion) as if they were turns.
 * History should keep the request, not those wrappers.
 */
final class ClaudeCodeTurnText
{
    /**
     * Text worth storing as the person's turn. Empty when the request
     * was only a client side request or only reminder text.
     */
    public static function visibleRequest(string $text): string
    {
        $stripped = self::stripReminders($text);
        if ('' === $stripped || self::isSideRequest($stripped)) {
            return '';
        }

        return $stripped;
    }

    public static function isSideRequest(string $text): bool
    {
        $trimmed = ltrim($text);
        if ('' === $trimmed) {
            return false;
        }

        if (str_starts_with($trimmed, '[SUGGESTION MODE')) {
            return true;
        }

        if (1 === preg_match('/write the title in the predominant language/i', $trimmed)) {
            return true;
        }

        if (1 === preg_match('/^please write a .{0,80}title/i', $trimmed)) {
            return true;
        }

        return false;
    }

    public static function stripReminders(string $text): string
    {
        $withoutTags = preg_replace('/<system-reminder>[\s\S]*?<\/system-reminder>/i', '', $text);
        $text = \is_string($withoutTags) ? $withoutTags : $text;

        $withoutAttribution = preg_replace(
            '/Attribution for git commits and pull requests you create from here on[\s\S]*?Co-Authored-By:[^\n]*/i',
            '',
            $text,
        );
        $text = \is_string($withoutAttribution) ? $withoutAttribution : $text;

        return trim($text);
    }
}
