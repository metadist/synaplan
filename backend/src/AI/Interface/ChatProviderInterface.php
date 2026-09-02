<?php

namespace App\AI\Interface;

use App\AI\StructuredOutput\StructuredOutputSchema;

/**
 * Chat Provider Interface.
 *
 * Generic interface for text-based AI chat providers.
 * Business logic (prompts, parsing, etc.) belongs in Services, not Providers.
 *
 * Streaming callback contract:
 *   - Content chunks:  fn(string $text) or fn(['type' => 'content', 'content' => $text])
 *   - Reasoning chunks: fn(['type' => 'reasoning', 'content' => $text])
 *   - Finish signal:    fn(['type' => 'finish', 'finish_reason' => 'stop'|'length'|...])
 *     Providers SHOULD emit a finish signal as the last callback invocation so callers
 *     can detect truncated responses (finish_reason = 'length').
 *
 * Structured output contract:
 *   - $options may carry a `structured_output` key holding a
 *     {@see StructuredOutputSchema}. A provider that supports schema-enforced
 *     JSON (see {@see \App\AI\StructuredOutput\StructuredOutputCapability})
 *     SHOULD translate it into its own dialect via
 *     {@see \App\AI\StructuredOutput\StructuredOutputTranslator} and merge the
 *     result into its request payload. A provider/model/streaming
 *     combination that does NOT support it MUST silently ignore the option
 *     and fall back to today's free-text behavior — never throw. Callers are
 *     responsible for checking {@see \App\AI\StructuredOutput\StructuredOutputCapability::supports()}
 *     when they need to know whether the schema was actually honoured (e.g.
 *     to decide whether server-side enum validation is still required).
 *
 * Native tool-calling contract:
 *   - $options may carry a `tools` key holding a list of
 *     {@see \App\AI\ToolCalling\ToolDefinition}s. The same rules as for
 *     structured output apply: translate via
 *     {@see \App\AI\ToolCalling\ToolCallingTranslator}, and silently ignore
 *     the option when the provider/model/streaming combination cannot do it
 *     ({@see \App\AI\ToolCalling\ToolCallingCapability}) — never throw.
 *   - Tool calls come back in `tool_calls` as normalised
 *     {@see \App\AI\ToolCalling\ToolCall}s, on the streaming path too, where
 *     they are accumulated rather than emitted to the callback.
 *   - This is a SINGLE-SHOT contract, not an agentic loop: providers never
 *     execute a tool or continue the conversation with a tool result. The
 *     full loop lives in {@see \App\AI\Messages\Tools\GatewayToolLoop}.
 */
interface ChatProviderInterface extends ProviderMetadataInterface
{
    /**
     * Default max completion tokens when not specified via options.
     *
     * Conservative fallback that all models support. Providers use this
     * when a model config omits max_tokens. Models are encouraged to
     * declare their actual limit via max_tokens in ModelCatalog JSON.
     */
    public const DEFAULT_MAX_COMPLETION_TOKENS = 4096;

    /**
     * Generate chat completion (non-streaming).
     *
     * @param array $messages Messages array in OpenAI format: [['role' => 'user', 'content' => '...']]
     * @param array $options  options: model (required), temperature, max_tokens, reasoning, etc
     *
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}, response_id?: ?string, tool_calls?: list<\App\AI\ToolCalling\ToolCall>}
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Generate chat completion (streaming).
     *
     * @param array    $messages Messages array in OpenAI format
     * @param callable $callback callback for each chunk: fn(string|array $chunk)
     *                           See class-level docblock for the full callback contract
     * @param array    $options  options: model (required), temperature, max_tokens, reasoning, etc
     *
     * @return array{usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}, response_id?: ?string, tool_calls?: list<\App\AI\ToolCalling\ToolCall>}
     */
    public function chatStream(array $messages, callable $callback, array $options = []): array;
}
