<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;
use App\Repository\ProjectRepository;

/**
 * Resolves the project of a chat for one turn. The folder comes from the
 * chat's own `BPROJECTID`, never from a request parameter. A chat whose
 * project is gone, belongs to someone else, or whose feature is off, is a
 * plain chat.
 */
final readonly class ProjectChatScope
{
    public function __construct(
        private ProjectsConfig $config,
        private ProjectRepository $projects,
    ) {
    }

    public function forChat(?Chat $chat): ?ProjectTurnContext
    {
        $projectId = $chat?->getProjectId();
        if (null === $chat || null === $projectId || !$this->config->isEnabled()) {
            return null;
        }
        $project = $this->projects->findById($projectId);
        if (!$project instanceof Project || $project->getUserId() !== $chat->getUserId()) {
            return null;
        }

        return new ProjectTurnContext(
            $project->getGroupKey(),
            $project->getName(),
            $this->cut($project->getInstructions()),
        );
    }

    private function cut(?string $instructions): ?string
    {
        if (null === $instructions || '' === trim($instructions)) {
            return null;
        }

        return mb_substr($instructions, 0, $this->config->instructionsMaxChars());
    }
}
