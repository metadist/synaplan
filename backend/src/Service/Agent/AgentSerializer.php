<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\User;

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
     * Gallery card — never includes the draft JSON.
     *
     * @return array<string, mixed>
     */
    public function galleryCard(Agent $agent, string $ownerName, string $origin = 'mine'): array
    {
        return [
            'id' => $agent->getId(),
            'slug' => $agent->getSlug(),
            'name' => $agent->getName(),
            'description' => $agent->getDescription(),
            'icon' => $agent->getIcon(),
            'status' => $agent->getStatus(),
            'origin' => $origin,
            'ownerName' => $ownerName,
            'version' => null,
            'updatedAt' => $agent->getUpdated(),
            'starterPrompts' => $this->starterPrompts($agent),
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

    public function displayName(User $user): string
    {
        $details = $user->getUserDetails();
        foreach (['full_name', 'first_name'] as $key) {
            $value = $details[$key] ?? null;
            if (is_string($value) && '' !== trim($value)) {
                return trim($value);
            }
        }

        return (string) $user->getMail();
    }

    /**
     * @return list<string>
     */
    private function starterPrompts(Agent $agent): array
    {
        $draft = $agent->getDraft();
        $behaviour = $draft['behaviour'] ?? null;
        if (!is_array($behaviour)) {
            return [];
        }
        $raw = $behaviour['starterPrompts'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $item) {
            if (!is_string($item)) {
                continue;
            }
            $text = trim($item);
            if ('' === $text) {
                continue;
            }
            $out[] = $text;
            if (3 === count($out)) {
                break;
            }
        }

        return $out;
    }
}
