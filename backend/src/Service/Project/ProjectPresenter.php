<?php

declare(strict_types=1);

namespace App\Service\Project;

use App\Entity\Chat;
use App\Entity\Project;
use App\Service\Project\Item\ProjectZone;

/**
 * The JSON shape of a project and of a chat in a project.
 */
final readonly class ProjectPresenter
{
    public function __construct(private ProjectZone $zone)
    {
    }

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
            'pinned' => $project->isPinned(),
            'timezone' => $this->zone->of($project)->getName(),
            'currency' => $project->getCurrency(),
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
