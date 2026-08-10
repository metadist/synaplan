<?php

declare(strict_types=1);

namespace App\AI\Messages\Tools;

/**
 * Classifies entries in an Anthropic `tools[]` array.
 *
 * Anthropic knows two kinds of entry. A *client* tool carries a JSON Schema in
 * `input_schema` and is executed by whoever sent the request. A *server* tool
 * is a capability request — `{"type": "web_search_20250305", "name":
 * "web_search"}` — with no schema, executed by the API side.
 *
 * The distinction matters everywhere the gateway leaves Anthropic's protocol:
 * a server tool cannot be forwarded to OpenAI or Gemini, and turning one into a
 * function declaration would offer the model a tool that nobody can answer.
 */
final class AnthropicServerTools
{
    /** Anthropic web search server tool (`web_search_20250305`, `web_search_20260209`, …). */
    public const WEB_SEARCH_TYPE_PREFIX = 'web_search_';

    /** Anthropic web fetch server tool (`web_fetch_20250910`, `web_fetch_20260209`, …). */
    public const WEB_FETCH_TYPE_PREFIX = 'web_fetch_';

    /** Canonical name Anthropic (and Synaplan's passthrough) use for page fetch. */
    public const WEB_FETCH_NAME = 'web_fetch';

    /** Explicit marker some clients set on ordinary client tools. */
    private const CLIENT_TOOL_TYPE = 'custom';

    /**
     * @param array<string, mixed> $tool
     */
    public static function isServerToolDeclaration(array $tool): bool
    {
        $type = $tool['type'] ?? null;
        if (!\is_string($type) || '' === $type || self::CLIENT_TOOL_TYPE === $type) {
            return false;
        }

        // A schema means the sender described something callable, so treat it
        // as a client tool even if it also carries a type.
        return !isset($tool['input_schema']);
    }

    /**
     * @param array<string, mixed> $tool
     */
    public static function isWebSearch(array $tool): bool
    {
        $type = $tool['type'] ?? null;

        return self::isServerToolDeclaration($tool)
            && \is_string($type)
            && str_starts_with($type, self::WEB_SEARCH_TYPE_PREFIX);
    }

    /**
     * @param array<string, mixed> $tool
     */
    public static function isWebFetch(array $tool): bool
    {
        $type = $tool['type'] ?? null;

        return self::isServerToolDeclaration($tool)
            && \is_string($type)
            && str_starts_with($type, self::WEB_FETCH_TYPE_PREFIX);
    }
}
