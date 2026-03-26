<?php

namespace App\AI\Interface;

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
 */
interface ChatProviderInterface extends ProviderMetadataInterface
{
    /**
     * Default max completion tokens when not specified via options.
     *
     * Conservative fallback that all models support. Each model should
     * declare its actual limit via max_tokens in ModelCatalog JSON.
     */
    public const DEFAULT_MAX_COMPLETION_TOKENS = 4096;

    /**
     * Generate chat completion (non-streaming).
     *
     * @param array $messages Messages array in OpenAI format: [['role' => 'user', 'content' => '...']]
     * @param array $options  options: model (required), temperature, max_tokens, reasoning, etc
     *
     * @return string Response content
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Generate chat completion (streaming).
     *
     * @param array    $messages Messages array in OpenAI format
     * @param callable $callback callback for each chunk: fn(string|array $chunk)
     *                           See class-level docblock for the full callback contract
     * @param array    $options  options: model (required), temperature, max_tokens, reasoning, etc
     *
     * @return void Chunks are sent via callback
     */
    public function chatStream(array $messages, callable $callback, array $options = []): void;
}
