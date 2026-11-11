<?php

declare(strict_types=1);

namespace App\AI\Provider\Google;

/**
 * Extracts and replays Gemini 3+ / Gemini 4 `thoughtSignature` tokens on tool calls.
 *
 * Gemini 3.x and Gemini 4 require the signature returned alongside a `functionCall`
 * to be echoed back on the `Part` (not nested inside `functionCall`) on the next
 * `generateContent` / `streamGenerateContent` round, or Google rejects the turn
 * with HTTP 400.
 */
final class GoogleThoughtSignature
{
    /**
     * @param array<string, mixed> $part         Gemini part or candidate container
     * @param array<string, mixed> $functionCall Inner `functionCall` map when present
     */
    public static function fromPart(array $part, array $functionCall = []): ?string
    {
        foreach ([
            $functionCall['thoughtSignature'] ?? null,
            $functionCall['thought_signature'] ?? null,
            $part['thoughtSignature'] ?? null,
            $part['thought_signature'] ?? null,
        ] as $sig) {
            if (\is_string($sig) && '' !== $sig) {
                return $sig;
            }
        }

        return null;
    }

    /**
     * Read a preserved signature from an OpenAI-shaped assistant `tool_calls` entry.
     *
     * @param array<string, mixed> $call
     */
    public static function fromToolCall(array $call): ?string
    {
        $extraGoogle = \is_array($call['extra_content']['google'] ?? null)
            ? $call['extra_content']['google']
            : [];

        foreach ([
            $call['thought_signature'] ?? null,
            $call['thoughtSignature'] ?? null,
            $extraGoogle['thought_signature'] ?? null,
            $extraGoogle['thoughtSignature'] ?? null,
        ] as $sig) {
            if (\is_string($sig) && '' !== $sig) {
                return $sig;
            }
        }

        return null;
    }
}
