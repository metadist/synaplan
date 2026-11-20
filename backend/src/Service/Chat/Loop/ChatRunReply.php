<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * One model answer inside a chat run: its text, the tool calls it asked
 * for, and what the call cost in micro units.
 */
final readonly class ChatRunReply
{
    /**
     * @param list<array{id: string, name: string, args: array<string, mixed>, signature?: string}> $toolCalls
     */
    public function __construct(
        public string $content,
        public array $toolCalls = [],
        public int $costMicro = 0,
    ) {
    }
}
