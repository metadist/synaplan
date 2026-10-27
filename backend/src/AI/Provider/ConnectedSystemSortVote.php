<?php

declare(strict_types=1);

namespace App\AI\Provider;

/**
 * Recognises a connected system named in the sorter prompt and in the message.
 *
 * The dev sorter ({@see TestProvider}) does not read the connected-systems
 * hint, so a lookup such as "find my Notion page" was voted a single step and
 * the planner — the only path that can call the connection — never ran.
 * Matching the names the hint already quoted keeps that vote aligned with a
 * real sorter. Names shorter than three characters are ignored: "AI" or "ID"
 * would otherwise match ordinary sentences.
 */
final class ConnectedSystemSortVote
{
    private const MIN_NAME_CHARS = 3;

    public static function matches(string $text, string $systemPrompt): bool
    {
        if (!str_contains($systemPrompt, '## Connected systems of this user')) {
            return false;
        }

        if (!preg_match_all('/^- ("(?:\\\\.|[^"\\\\])*") \(connected data source\)/m', $systemPrompt, $matches)) {
            return false;
        }

        foreach ($matches[1] as $encoded) {
            $name = json_decode($encoded, true);
            if (is_string($name) && self::containsWord($text, $name)) {
                return true;
            }
        }

        return false;
    }

    public static function containsWord(string $text, string $name): bool
    {
        $name = self::singleLine($name, 60);
        if (mb_strlen($name) < self::MIN_NAME_CHARS) {
            return false;
        }

        $pattern = '/(?<![\p{L}\p{N}_])'.preg_quote($name, '/').'(?![\p{L}\p{N}_])/iu';

        return 1 === preg_match($pattern, $text);
    }

    public static function singleLine(string $raw, int $max): string
    {
        $clean = preg_replace('/\p{C}+/u', ' ', $raw) ?? '';
        $clean = trim(preg_replace('/\s+/u', ' ', $clean) ?? '');
        if (mb_strlen($clean) > $max) {
            $clean = rtrim(mb_substr($clean, 0, $max - 1)).'…';
        }

        return $clean;
    }
}
