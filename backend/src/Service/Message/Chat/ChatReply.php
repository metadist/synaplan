<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Service\Document\DocumentEditResult;

/**
 * What the model answered for a chat turn, before the answer is checked.
 *
 * `metadata` carries provider, model, usage and response id — for a
 * streamed document edit also the file and changes the edit produced.
 * `documentEdit` is set when the document tool loop answered instead of a
 * plain completion. `sawVisibleText` is false when a stream ended without a
 * single visible token. `webSearchQuery` is set when the model answered with
 * a {@see FreshInfoRequest} instead of an answer.
 */
final readonly class ChatReply
{
    /**
     * @param array<string, mixed> $metadata
     * @param array<int, mixed>    $toolCalls
     */
    public function __construct(
        public mixed $content,
        public array $metadata,
        public array $toolCalls = [],
        public ?string $finishReason = null,
        public ?DocumentEditResult $documentEdit = null,
        public bool $sawVisibleText = true,
        public ?string $webSearchQuery = null,
    ) {
    }

    /**
     * The provider that answered, falling back to the chosen one when the
     * stream did not report it.
     */
    public function provider(ChatModelChoice $model): string
    {
        return is_string($this->metadata['provider'] ?? null) ? $this->metadata['provider'] : ($model->provider ?? 'unknown');
    }

    public function model(ChatModelChoice $model): string
    {
        return is_string($this->metadata['model'] ?? null) ? $this->metadata['model'] : ($model->modelName ?? 'unknown');
    }
}
