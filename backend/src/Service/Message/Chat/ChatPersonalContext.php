<?php

declare(strict_types=1);

namespace App\Service\Message\Chat;

use App\Entity\User;

/**
 * What a chat turn knows about the person: their stored memories, the
 * corrections they gave as feedback, and digests of older conversations.
 *
 * Each array is the result of its {@see \App\Service\Memory\ChatMemoryContextLoader}
 * method; `context` is the block for the system prompt.
 */
final readonly class ChatPersonalContext
{
    /**
     * @param array<string, mixed> $memories {@see \App\Service\Memory\ChatMemoryContextLoader::loadMemories()}
     * @param array<string, mixed> $feedback {@see \App\Service\Memory\ChatMemoryContextLoader::loadFeedback()}
     * @param array<string, mixed> $digests  {@see \App\Service\Memory\ChatMemoryContextLoader::loadDigests()}
     */
    public function __construct(
        public ?User $user = null,
        public array $memories = ['context' => '', 'memories' => [], 'disabledByRequest' => false, 'disabledByUser' => false, 'requestDisableContext' => []],
        public array $feedback = ['context' => '', 'feedbacks' => []],
        public array $digests = ['context' => '', 'digests' => []],
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function loadedMemories(): array
    {
        return $this->memories['memories'] ?? [];
    }
}
