<?php

declare(strict_types=1);

namespace App\AI\Stream;

/**
 * Canonical stream chunk value and helper for interpreting provider stream chunks.
 *
 * Providers emit either plain strings or structured arrays
 * (['type' => 'content'|'reasoning'|'finish'|'tool_call_delta', ...]) — see
 * {@see \App\AI\Interface\ChatProviderInterface}. Consumers that accumulate
 * user-visible text MUST go through visibleText() so internal
 * chain-of-thought ('reasoning') and control signals ('finish') never leak
 * into the rendered response (issue #1067).
 */
final readonly class StreamChunk
{
    /**
     * @param 'content'|'reasoning'|'tool_call_delta'|'finish'|'raw' $type
     * @param array<string, mixed>                                   $payload
     */
    public function __construct(
        public string $type,
        public string $content = '',
        public ?string $finishReason = null,
        public array $payload = [],
    ) {
    }

    /**
     * Normalize a raw provider chunk into a typed {@see self} instance.
     */
    public static function fromProviderChunk(mixed $chunk): self
    {
        if ($chunk instanceof self) {
            return $chunk;
        }

        if (\is_string($chunk)) {
            return new self(type: 'content', content: $chunk);
        }

        if (!\is_array($chunk)) {
            return new self(type: 'raw');
        }

        $rawType = $chunk['type'] ?? 'content';
        $type = \is_string($rawType) && \in_array($rawType, ['content', 'reasoning', 'tool_call_delta', 'finish', 'raw'], true)
            ? $rawType
            : 'raw';
        $content = \is_string($chunk['content'] ?? null) ? $chunk['content'] : '';
        $finishReason = \is_string($chunk['finish_reason'] ?? null) && '' !== $chunk['finish_reason']
            ? $chunk['finish_reason']
            : null;

        /* @var 'content'|'reasoning'|'tool_call_delta'|'finish'|'raw' $type */
        return new self(
            type: $type,
            content: $content,
            finishReason: $finishReason,
            payload: $chunk,
        );
    }

    /**
     * Extract the user-visible answer text from a stream chunk.
     *
     * Plain string chunks are visible text. Structured chunks contribute only
     * when their type is 'content'; untyped arrays carrying a content key are
     * treated as content for backward compatibility with older producers.
     *
     * @param string|array<string, mixed>|self $chunk
     */
    public static function visibleText(string|array|self $chunk): string
    {
        if ($chunk instanceof self) {
            return 'content' === $chunk->type ? $chunk->content : '';
        }

        if (!\is_array($chunk)) {
            return $chunk;
        }

        if ('content' !== ($chunk['type'] ?? 'content')) {
            return '';
        }

        $content = $chunk['content'] ?? '';

        return \is_string($content) ? $content : '';
    }
}
