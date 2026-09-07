<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;

final class AgentSerializer
{
    /**
     * List row — never includes the draft JSON.
     *
     * @return array<string, mixed>
     */
    public function summary(Agent $agent): array
    {
        return [
            'id' => $agent->getId(),
            'slug' => $agent->getSlug(),
            'name' => $agent->getName(),
            'description' => $agent->getDescription(),
            'icon' => $agent->getIcon(),
            'status' => $agent->getStatus(),
            'updatedAt' => $agent->getUpdated(),
        ];
    }

    /**
     * Full owner payload including the validated draft.
     *
     * @return array<string, mixed>
     */
    public function full(Agent $agent): array
    {
        return [
            'id' => $agent->getId(),
            'slug' => $agent->getSlug(),
            'name' => $agent->getName(),
            'description' => $agent->getDescription(),
            'icon' => $agent->getIcon(),
            'status' => $agent->getStatus(),
            'promptId' => $agent->getPromptId(),
            'parentId' => $agent->getParentId(),
            'source' => $agent->getSource(),
            'routable' => $agent->isRoutable(),
            'publishedVersionId' => $agent->getPublishedVersionId(),
            'draft' => $agent->getDraft(),
            'createdAt' => $agent->getCreated(),
            'updatedAt' => $agent->getUpdated(),
        ];
    }
}
