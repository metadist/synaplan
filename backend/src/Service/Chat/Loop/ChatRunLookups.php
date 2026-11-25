<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

/**
 * What a run can look up in its own chat, answered by the loop itself:
 * earlier messages ({@see ChatRunConversationSearch}) and files by ID
 * ({@see ChatRunFileTools}). All of them only read.
 */
final readonly class ChatRunLookups
{
    public const NAMES = [ChatRunConversationSearch::NAME, ...ChatRunFileTools::NAMES];

    public function __construct(
        private ChatRunConversationSearch $conversation,
        private ChatRunFileTools $files,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function declarations(ChatRunScope $scope): array
    {
        return [$this->conversation->declaration(), ...$this->files->declarations($scope)];
    }

    public function handles(string $name): bool
    {
        return \in_array($name, self::NAMES, true);
    }

    /** @param array{id: string, name: string, args: array<string, mixed>} $call */
    public function run(ChatRunScope $scope, ChatRunState $state, array $call): ChatToolResult
    {
        if (ChatRunConversationSearch::NAME === $call['name']) {
            return $this->conversation->run($scope, $call['args'], array_values(array_filter([$state->incomingMessageId, $state->replyMessageId], static fn (?int $id): bool => null !== $id && $id > 0)));
        }

        return $this->files->run($scope, $state, $call['name'], $call['args']);
    }
}
