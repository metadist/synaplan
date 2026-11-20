<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * The first model call of a loop turn, made in the request: the messages and
 * options it is sent with, and what a run needs to go on from its answer.
 *
 * The base prompt is the chat's own system prompt without the project's
 * instructions and sources, which every unit resolves again.
 */
final readonly class ChatLoopCall
{
    /**
     * @param list<array<string, mixed>> $messages   sent in the request, the loop rules included
     * @param array<string, mixed>       $aiOptions  sent in the request, the tools included
     * @param list<array<string, mixed>> $transcript the messages after the system prompt
     */
    public function __construct(
        public array $messages,
        public array $aiOptions,
        public string $basePrompt,
        public array $transcript,
        public ChatRunScope $scope,
    ) {
    }
}
