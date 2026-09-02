<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Reads tool calls back out of a COMPLETE (non-streaming) provider response
 * and normalises them into {@see ToolCall}s.
 *
 * For the streaming case see {@see StreamingToolCallAccumulator} — tool calls
 * arrive there in fragments and cannot be parsed from a single payload.
 *
 * Robustness contract: a response that carries no tool calls, or carries them
 * in an unexpected shape, yields an empty list rather than an exception. The
 * caller ({@see \App\Service\Message\Handler\ChatHandler}) treats "no tool
 * call" as "the model answered directly", which is the safe reading — the
 * user still gets their answer.
 */
final class ToolCallParser
{
    /**
     * @param array<string, mixed> $rawResponse the provider's decoded response body
     *
     * @return list<ToolCall>
     */
    public function parse(ToolCallingDialect $dialect, array $rawResponse): array
    {
        return match ($dialect) {
            ToolCallingDialect::OPENAI_FUNCTIONS => $this->parseOpenAiFunctions($rawResponse),
            ToolCallingDialect::ANTHROPIC_TOOLS => $this->parseAnthropicTools($rawResponse),
        };
    }

    /**
     * @param array<string, mixed> $rawResponse
     *
     * @return list<ToolCall>
     */
    private function parseOpenAiFunctions(array $rawResponse): array
    {
        $choices = $rawResponse['choices'] ?? null;
        if (!is_array($choices) || !is_array($choices[0] ?? null)) {
            return [];
        }

        $message = $choices[0]['message'] ?? null;
        $rawCalls = is_array($message) ? ($message['tool_calls'] ?? null) : null;
        if (!is_array($rawCalls)) {
            return [];
        }

        $calls = [];
        foreach ($rawCalls as $rawCall) {
            if (!is_array($rawCall) || !is_array($rawCall['function'] ?? null)) {
                continue;
            }

            $name = $rawCall['function']['name'] ?? null;
            if (!is_string($name) || '' === $name) {
                continue;
            }

            $calls[] = new ToolCall(
                id: is_string($rawCall['id'] ?? null) ? $rawCall['id'] : '',
                name: $name,
                // OpenAI sends the arguments as a JSON-encoded STRING, not an
                // object — and a model that emits a truncated or malformed
                // blob must still count as having called the tool.
                arguments: self::decodeArguments($rawCall['function']['arguments'] ?? null),
            );
        }

        return $calls;
    }

    /**
     * @param array<string, mixed> $rawResponse
     *
     * @return list<ToolCall>
     */
    private function parseAnthropicTools(array $rawResponse): array
    {
        $content = $rawResponse['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        $calls = [];
        foreach ($content as $block) {
            if (!is_array($block) || 'tool_use' !== ($block['type'] ?? null)) {
                continue;
            }

            $name = $block['name'] ?? null;
            if (!is_string($name) || '' === $name) {
                continue;
            }

            // Anthropic already hands `input` back as a decoded object.
            $input = $block['input'] ?? null;

            $calls[] = new ToolCall(
                id: is_string($block['id'] ?? null) ? $block['id'] : '',
                name: $name,
                arguments: is_array($input) ? $input : [],
            );
        }

        return $calls;
    }

    /**
     * Decode an argument blob that may be a JSON string (OpenAI), an already
     * decoded map, or junk.
     *
     * @return array<string, mixed>
     */
    public static function decodeArguments(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw) || '' === trim($raw)) {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
