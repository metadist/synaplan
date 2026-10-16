<?php

declare(strict_types=1);

namespace App\Service\Chat\Stream;

/**
 * How a streamed turn runs: reasoning, web search, voice reply, memories,
 * the model or prompt the person picked, the knowledge-base folder, and the
 * pinned assistant.
 */
final readonly class StreamTurnOptions
{
    /**
     * @param string      $reasoningEffort      the reasoning level; empty means the model default
     * @param string|null $modelId              the model id as sent; "Again" replays it, otherwise it overrides
     * @param string|null $fixedTaskPromptTopic the widget's task prompt, or the prompt the API caller selected
     */
    public function __construct(
        public bool $reasoning = false,
        public string $reasoningEffort = '',
        public bool $webSearch = false,
        public bool $voiceReply = false,
        public bool $disableMemories = false,
        public bool $disableKnowledge = false,
        public ?string $modelId = null,
        public bool $isAgain = false,
        public ?string $fixedTaskPromptTopic = null,
        public ?string $ragGroupKey = null,
        public ?StreamAgentPin $agent = null,
    ) {
    }

    /**
     * The model id as an int, for the lookups that need one.
     */
    public function modelIdAsInt(): ?int
    {
        return null !== $this->modelId ? (int) $this->modelId : null;
    }
}
