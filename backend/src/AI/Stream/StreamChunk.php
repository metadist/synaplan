<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * Helper for interpreting provider stream chunks.
 *
 * Providers emit either plain strings or structured arrays
 * (['type' => 'content'|'reasoning'|'finish', ...]) — see
 * {@see \App\AI\Interface\ChatProviderInterface}. Consumers that accumulate
 * user-visible text MUST go through visibleText() so internal
 * chain-of-thought ('reasoning') and control signals ('finish') never leak
 * into the rendered response (issue #1067).
 */
final class StreamChunk
{
    private function __construct()
    {
    }

    /**
     * Extract the user-visible answer text from a stream chunk.
     *
     * Plain string chunks are visible text. Structured chunks contribute only
     * when their type is 'content'; untyped arrays carrying a content key are
     * treated as content for backward compatibility with older producers.
     *
     * @param string|array<string, mixed> $chunk
     */
    public static function visibleText(string|array $chunk): string
    {
        if (!is_array($chunk)) {
            return $chunk;
        }

        if ('content' !== ($chunk['type'] ?? 'content')) {
            return '';
        }

        $content = $chunk['content'] ?? '';

        return is_string($content) ? $content : '';
    }
}
