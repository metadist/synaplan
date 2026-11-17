<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;

/**
 * The JSON shape of a project and of a chat in a project.
 */
final readonly class ProjectPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function project(Project $project): array
    {
        return [
            'id' => $project->getUuid(),
            'name' => $project->getName(),
            'description' => $project->getDescription(),
            'instructions' => $project->getInstructions(),
            'createdAt' => $project->getCreated()->format('c'),
            'updatedAt' => $project->getUpdated()->format('c'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function chat(Chat $chat): array
    {
        return [
            'id' => $chat->getId(),
            'title' => $chat->getTitle() ?? 'New Chat',
            'updatedAt' => $chat->getUpdatedAt()->format('c'),
        ];
    }
}
