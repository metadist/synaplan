<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;
use App\Repository\ChatRepository;

/**
 * Moves a chat into a project or out of it. Moving out removes the access to
 * the project's sources for later turns; the earlier answers stay as they are.
 * Only web chats join: the other channels never read the project.
 */
final readonly class ProjectChatMembership
{
    private const TITLE_MAX = 255;

    public function __construct(
        private ProjectService $projects,
        private ProjectsConfig $config,
        private ChatRepository $chats,
    ) {
    }

    /**
     * Starts a new web chat of the project's owner inside the project.
     */
    public function start(Project $project, ?string $title): Chat
    {
        $chat = new Chat();
        $chat->setUserId($project->getUserId());
        $title = null === $title ? '' : trim($title);
        if ('' !== $title) {
            $chat->setTitle(mb_substr($title, 0, self::TITLE_MAX));
        }
        $this->move($chat, $project->getUuid());

        return $chat;
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
        if (Chat::SOURCE_WEB !== $chat->getSource()) {
            throw ProjectException::invalid('Only web chats can join a project');
        }
        $project = $this->projects->get($projectUuid, $chat->getUserId());
        $chat->setProjectId((int) $project->getId());
        $this->chats->save($chat);
    }
}
