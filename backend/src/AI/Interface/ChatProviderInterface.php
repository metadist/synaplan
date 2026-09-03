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
 *   - Tool-call deltas: fn(['type' => 'tool_call_delta', 'index' => int, 'id' => ?string,
 *                           'name' => ?string, 'arguments' => string])
 *     Folded by {@see \App\AI\Tool\ToolCallAccumulator}. `visibleText()` is empty
 *     for this type so tool JSON never leaks into the rendered answer.
 *   - Finish signal:    fn(['type' => 'finish', 'finish_reason' => 'stop'|'length'|'tool_calls'|...])
 *     Providers SHOULD emit a finish signal as the last callback invocation so callers
 *     can detect truncated responses (finish_reason = 'length') and tool turns.
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
 *   - A schema always wins over declared `tools`: on a provider that cannot
 *     carry both in one request
 *     ({@see \App\AI\ToolCalling\ToolCallingCapability::conflictsWithStructuredOutput()})
 *     the provider MUST drop the tools rather than the schema.
 *
 * Additive tool-calling contract (ignored by providers that do not implement
 * {@see ToolCallingChatProviderInterface}):
 *   - Options: `tools` (OpenAI function declarations
 *     `[{type:'function', function:{name, description?, parameters?}}]`),
 *     `tool_choice` (`'auto'|'none'|'required'|{type:'function',function:{name}}`),
 *     `parallel_tool_calls` (bool).
 *   - Input messages may contain assistant `tool_calls` and `role: 'tool'`
 *     (`tool_call_id`, `content`) entries, and array `content`.
 *   - `chat()` may also return `tool_calls?: list<{id, type:'function',
 *     function:{name, arguments: string}}>` and `finish_reason?: string`.
 *     Callers that want the normalised DTO form read them through
 *     {@see \App\AI\ToolCalling\ToolCallParser::fromWireToolCalls()}.
 * Non-tool providers MUST ignore the extra options and keep returning text.
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
     * @param array $options  options: model (required), temperature, max_tokens, reasoning,
     *                        tools, tool_choice, parallel_tool_calls
     *
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}, response_id?: ?string, tool_calls?: list<array<string, mixed>>, finish_reason?: string}
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Generate chat completion (streaming).
     *
     * @param array    $messages Messages array in OpenAI format
     * @param callable $callback callback for each chunk: fn(string|array $chunk)
     *                           See class-level docblock for the full callback contract
     * @param array    $options  options: model (required), temperature, max_tokens, reasoning,
     *                           tools, tool_choice, parallel_tool_calls
     *
     * @return array{usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}, response_id?: ?string, tool_calls?: list<array<string, mixed>>, finish_reason?: string}
     */
    public function chatStream(array $messages, callable $callback, array $options = []): array;
}
