<?php

declare(strict_types=1);

namespace App\AI\Messages;

/**
 * Human text from an Anthropic-shaped message content value.
 *
 * Desktop and Claude Code send tool results as user-role content blocks.
 * Those blocks are not something the person typed, and storing the JSON
 * makes the chat history a wall of tool_result objects.
 */
final class AnthropicContentText
{
    /**
     * Newest user turn that still contains text a person typed.
     * A trailing tool-only turn must not hide the request before it.
     *
     * @param array<int|string, mixed> $messages
     */
    public static function lastHumanRequest(array $messages): string
    {
        foreach (array_reverse($messages) as $message) {
            if (!\is_array($message) || 'user' !== ($message['role'] ?? '')) {
                continue;
            }
            $text = trim(self::humanText($message['content'] ?? ''));
            if ('' !== $text) {
                return $text;
            }
        }

        return '';
    }

    /**
     * Text a person would recognize. Tool blocks are dropped. An empty
     * string means the turn was only tool JSON.
     *
     * Values that are not content blocks are left readable: a string is
     * returned as-is, any other array is JSON-encoded.
     */
    public static function humanText(mixed $content): string
    {
        if (\is_string($content)) {
            $collapsed = self::collapseStored($content);

            return null !== $collapsed ? $collapsed : $content;
        }

        $fromBlocks = self::fromValue($content);
        if (null !== $fromBlocks) {
            return $fromBlocks;
        }

        if (\is_array($content)) {
            $encoded = json_encode($content, \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_UNESCAPED_UNICODE);

            return \is_string($encoded) ? $encoded : '';
        }

        return '';
    }

    /**
     * Stored bubble text that is tool JSON.
     *
     * @return string|null extracted text, '' when the payload is only tool
     *                     blocks, null when $text is ordinary prose
     */
    public static function collapseStored(string $text): ?string
    {
        $trimmed = trim($text);
        if ('' === $trimmed || (!str_starts_with($trimmed, '{') && !str_starts_with($trimmed, '['))) {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return self::fromValue($decoded);
    }

    private static function fromValue(mixed $value): ?string
    {
        if (!\is_array($value)) {
            return null;
        }

        if (self::isBlock($value)) {
            if (self::isToolBlock($value)) {
                return '';
            }
            if ('text' === $value['type'] && \is_string($value['text'] ?? null)) {
                return trim($value['text']);
            }

            return null;
        }

        if (!array_is_list($value) || [] === $value) {
            return null;
        }

        $texts = [];
        $hasTool = false;
        foreach ($value as $block) {
            if (!self::isBlock($block)) {
                return null;
            }
            if (self::isToolBlock($block)) {
                $hasTool = true;
                continue;
            }
            if ('text' === $block['type'] && \is_string($block['text'] ?? null)) {
                $piece = trim($block['text']);
                if ('' !== $piece) {
                    $texts[] = $piece;
                }
            }
        }

        if (!$hasTool && [] === $texts) {
            return null;
        }

        return implode("\n\n", $texts);
    }

    /**
     * @phpstan-assert-if-true array{type: string, text?: mixed} $value
     */
    private static function isBlock(mixed $value): bool
    {
        return \is_array($value)
            && isset($value['type'])
            && \is_string($value['type'])
            && '' !== $value['type'];
    }

    /**
     * @param array{type: string, text?: mixed} $block
     */
    private static function isToolBlock(array $block): bool
    {
        return \in_array($block['type'], ['tool_result', 'tool_use'], true);
    }
}
