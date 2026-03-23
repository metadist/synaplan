<?php

namespace App\AI\Interface;

/**
 * Chat Provider Interface.
 *
 * Generic interface for text-based AI chat providers.
 * Business logic (prompts, parsing, etc.) belongs in Services, not Providers.
 */
interface ChatProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generate chat completion (non-streaming).
     *
     * @param array $messages Messages array in OpenAI format: [['role' => 'user', 'content' => '...']]
     * @param array $options  options: model (required), temperature, max_tokens, reasoning, etc
     *
     * @return array{content: string, usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}}
     */
    public function chat(array $messages, array $options = []): array;

    /**
     * Generate chat completion (streaming).
     *
     * @param array    $messages Messages array in OpenAI format
     * @param callable $callback Callback for each chunk: fn(array $chunk) where chunk has 'type' and 'content'
     * @param array    $options  options: model (required), temperature, max_tokens, reasoning, etc
     *
     * @return array{usage: array{prompt_tokens: int, completion_tokens: int, total_tokens: int, cached_tokens: int, cache_creation_tokens: int}}
     */
    public function chatStream(array $messages, callable $callback, array $options = []): array;
}
