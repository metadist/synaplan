<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Turns the `tool_calls` a provider returns on the wire into normalised
 * {@see ToolCall}s.
 *
 * Providers already speak one shape here — OpenAI's
 * `[{id, type:'function', function:{name, arguments}}]`, which
 * {@see \App\AI\Interface\ChatProviderInterface} documents and the Anthropic
 * and Gemini providers map their own formats into. Callers that are happy
 * with arrays (the gateway loops, the document tool loop) read them
 * directly; callers with routing logic to run go through this class to get
 * typed access and decoded arguments.
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
     * @param mixed $rawToolCalls the `tool_calls` entry of a provider response
     *
     * @return list<ToolCall>
     */
    public function fromWireToolCalls(mixed $rawToolCalls): array
    {
        if (!is_array($rawToolCalls)) {
            return [];
        }

        $calls = [];
        foreach ($rawToolCalls as $rawCall) {
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
                // The arguments travel as a JSON-encoded STRING, and a model
                // that emits a truncated or malformed blob must still count
                // as having called the tool.
                arguments: self::decodeArguments($rawCall['function']['arguments'] ?? null),
            );
        }

        return $calls;
    }

    /**
     * Decode an argument blob that may be a JSON string, an already decoded
     * map, or junk.
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
