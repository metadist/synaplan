<?php

declare(strict_types=1);

namespace App\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\User;
use App\Repository\ChatRepository;
use App\Repository\UserRepository;
use App\Service\Project\ProjectChatScope;

/**
 * Reads the person, the chat and the project from the database at every
 * unit, never from the checkpoint: a suspended account, a deleted chat or a
 * chat moved out of its project takes effect at the next unit.
 */
final readonly class ResolvingChatRunWorld implements ChatRunWorld
{
    public function __construct(
        private UserRepository $users,
        private ChatRepository $chats,
        private ProjectChatScope $projects,
        private ChatLoopConfig $config,
    ) {
    }

    public function resolve(ChatRunState $state): ChatRunScope|ChatRunEnd
    {
        if (!$this->config->isEnabled()) {
            return ChatRunEnd::LoopOff;
        }
        $user = $this->users->find($state->userId);
        if (!$user instanceof User || !$user->isActive()) {
            return ChatRunEnd::AccountGone;
        }
        $chat = $this->chats->find($state->chatId);
        if (!$chat instanceof Chat || $chat->getUserId() !== $state->userId) {
            return ChatRunEnd::ChatGone;
        }

        return new ChatRunScope($user, $chat, $this->projects->forChat($chat));
    }
}
