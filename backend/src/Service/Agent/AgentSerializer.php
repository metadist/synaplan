<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Entity\AgentVersion;
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
     * `$visibleDefinition` is the definition the viewer is allowed to see:
     * the live draft for the owner, the published snapshot for everyone
     * else. Starter prompts are read from it, so a recipient never sees
     * text the owner is still editing.
     *
     * @param array<string, mixed>                   $visibleDefinition
     * @param array{type: string, name: string}|null $sharedVia
     *
     * @return array<string, mixed>
     */
    public function galleryCard(
        Agent $agent,
        array $visibleDefinition,
        string $ownerName,
        string $origin = 'mine',
        ?int $version = null,
        ?array $sharedVia = null,
        ?string $permission = null,
    ): array {
        $canEdit = 'mine' === $origin || in_array($permission, ['edit', 'manage'], true);
        $canStartChat = !$agent->isArchived() && ('mine' === $origin || in_array($permission, ['use', 'edit', 'manage'], true));

        return [
            'id' => $agent->getId(),
            'slug' => $agent->getSlug(),
            'name' => $agent->getName(),
            'description' => $agent->getDescription(),
            'icon' => $agent->getIcon(),
            'status' => $agent->getStatus(),
            'origin' => $origin,
            'ownerName' => $ownerName,
            'version' => $version,
            'updatedAt' => $agent->getUpdated(),
            'starterPrompts' => $this->starterPrompts($visibleDefinition),
            'sharedVia' => $sharedVia,
            'canEdit' => $canEdit,
            'canStartChat' => $canStartChat,
            'canClone' => true,
        ];
    }

    /**
     * Full owner / editor payload including the validated draft.
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

    /**
     * Reader payload — never the draft.
     *
     * @return array<string, mixed>
     */
    public function publicView(Agent $agent): array
    {
        $full = $this->full($agent);
        unset($full['draft']);

        return $full;
    }

    /**
     * Version list row — never the definition.
     *
     * @return array<string, mixed>
     */
    public function versionCard(AgentVersion $version, string $publisherName): array
    {
        return [
            'id' => $version->getId(),
            'version' => $version->getVersion(),
            'changelog' => $version->getChangelog(),
            'publishedByName' => $publisherName,
            'createdAt' => $version->getCreated(),
        ];
    }

    /**
     * Version detail for owner / edit — includes the snapshot.
     *
     * @return array<string, mixed>
     */
    public function versionDetail(AgentVersion $version, string $publisherName): array
    {
        return $this->versionCard($version, $publisherName) + [
            'definition' => $version->getDefinition(),
            'promptText' => $version->getPromptText(),
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
     * @param array<string, mixed> $definition
     *
     * @return list<string>
     */
    private function starterPrompts(array $definition): array
    {
        $behaviour = $definition['behaviour'] ?? null;
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
