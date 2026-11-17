<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Chat;
use App\Repository\ChatRepository;

/**
 * Moves a chat into a project or out of it. Moving out removes the access to
 * the project's sources for later turns; the earlier answers stay as they are.
 */
final readonly class ProjectChatMembership
{
    public function __construct(
        private ProjectService $projects,
        private ProjectsConfig $config,
        private ChatRepository $chats,
    ) {
    }

    /**
     * @param string|null $projectUuid the project to join, null to leave
     */
    public function move(Chat $chat, ?string $projectUuid): void
    {
        if (null === $projectUuid || '' === $projectUuid) {
            $chat->setProjectId(null);
            $this->chats->save($chat);

            return;
        }
        if (!$this->config->isEnabled()) {
            throw ProjectException::notFound('Project');
        }
        $project = $this->projects->get($projectUuid, $chat->getUserId());
        $chat->setProjectId((int) $project->getId());
        $this->chats->save($chat);
    }
}
