<?php

declare(strict_types=1);

namespace App\AI\ToolCalling;

/**
 * Answers "may the native tool-calling ROUTING path use this provider +
 * model + streaming-mode?" — the single place the hard, provider-specific
 * rules live, mirroring {@see \App\AI\StructuredOutput\StructuredOutputCapability}.
 *
 * This sits ON TOP of the transport-level gate. Whether a provider can carry
 * tools at all is answered by
 * {@see \App\AI\Interface\ToolCallingChatProviderInterface::supportsToolCalling()}
 * plus the catalog `tool_use` flag ({@see \App\AI\Tool\CatalogToolUse}), and
 * that is true for every chat provider. The list below is narrower on
 * purpose: it names the providers whose STREAMING path also hands the
 * accumulated calls back in `tool_calls`, which the routing hand-off needs
 * and the transport contract does not require.
 *
 * That narrowness is a feature, not a gap. The only consumer today is the
 * native tool-calling routing path, whose contract is that an unsupported
 * provider silently keeps the AI-sorter round-trip
 * ({@see \App\Service\Message\MessageClassifier}). A provider missing here
 * therefore behaves exactly as it does today; a provider wrongly listed here
 * would break the hottest path in the product.
 */
final class ToolCallingCapability
{
    /**
     * Providers wired end-to-end for the routing hand-off: the declarations
     * go out ({@see ToolCallingTranslator}) and the calls come back in
     * `tool_calls` on the provider's response, streaming included.
     *
     * Every chat provider can carry tools since the office-tools work, but
     * only these return the accumulated calls from `chatStream()` — the others
     * leave folding the `tool_call_delta` chunks to the caller, which the
     * routing hand-off does not do.
     *
     * Working while STREAMING is an entry condition, not a per-provider
     * flag: the streaming web path is the hot one, and a streamed tool call
     * nobody collects would answer the user with an empty message. A provider
     * that only does non-streaming tool calls does not belong here.
     */
    private const NATIVE_ROUTING_PROVIDERS = ['groq', 'anthropic'];

    /**
     * Providers for which a tool declaration and a JSON-schema response
     * format cannot travel in the same request.
     *
     * Two different reasons, same consequence:
     *   - `groq` documents the combination as unsupported and 400s on it.
     *   - `anthropic` has no native schema mode: structured output IS a forced
     *     tool call ({@see \App\AI\StructuredOutput\StructuredOutputDialect::ANTHROPIC_TOOL_FORCING}),
     *     so the schema and the declared tools write the same
     *     `tools`/`tool_choice` keys and whichever merges last silently
     *     erases the other.
     *
     * Enforced by the providers themselves rather than by a caller: tools
     * reach them from several places (routing hand-off, document tools, the
     * OpenAI-compatible gateway) and only the provider sees the moment where
     * both end up in one payload.
     */
    private const NO_TOOLS_WITH_STRUCTURED_OUTPUT = ['groq', 'anthropic'];

    /**
     * `$model` and `$streaming` are part of the question by design, mirroring
     * {@see \App\AI\StructuredOutput\StructuredOutputCapability::supports()}
     * where both decide. Here neither narrows the answer yet: the rules are
     * per provider, and streaming support is an entry condition of the list
     * rather than a variant of it.
     */
    public function supports(string $providerName, ?string $model, bool $streaming): bool
    {
        return in_array(strtolower($providerName), self::NATIVE_ROUTING_PROVIDERS, true);
    }

    public function conflictsWithStructuredOutput(string $providerName): bool
    {
        return in_array(strtolower($providerName), self::NO_TOOLS_WITH_STRUCTURED_OUTPUT, true);
    }
}
