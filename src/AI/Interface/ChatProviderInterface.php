<?php

namespace App\AI\Interface;

/**
 * Chat Provider Interface
 * 
 * Generic interface for text-based AI chat providers.
 * Business logic (prompts, parsing, etc.) belongs in Services, not Providers.
 */
interface ChatProviderInterface extends ProviderMetadataInterface
{
    /**
     * Generate chat completion (non-streaming)
     * 
     * @param array $messages Messages array in OpenAI format: [['role' => 'user', 'content' => '...']]
     * @param array $options Options: model (required), temperature, max_tokens, reasoning, etc.
     * @return string Response content
     */
    public function chat(array $messages, array $options = []): string;

    /**
     * Generate chat completion (streaming)
     * 
     * @param array $messages Messages array in OpenAI format
     * @param callable $callback Callback for each chunk: fn(string $chunk)
     * @param array $options Options: model (required), temperature, max_tokens, reasoning, etc.
     * @return void Chunks are sent via callback
     */
    public function chatStream(array $messages, callable $callback, array $options = []): void;
}

