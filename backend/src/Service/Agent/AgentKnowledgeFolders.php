<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Entity\Agent;
use App\Service\Agent\Definition\AgentDefinition;
use App\Service\Iam\ResourceKind\KnowledgeFolderKind;

/**
 * The RAG scopes an assistant searches: its own folder on the owner's files
 * (`TASKPROMPT:agent:{slug}`, unless switched off) plus every
 * `knowledge.folders` entry, which is a knowledge-folder resource id
 * (`{ownerId}:{groupKey}`) and may point at another user's shared folder.
 *
 * One place for this so the runtime resolver (which builds the scopes) and
 * the RAG access check (which decides what a recipient may read) never
 * disagree about what "the assistant's knowledge" is.
 */
final class AgentKnowledgeFolders
{
    public const OWN_FOLDER_PREFIX = 'TASKPROMPT:'.Agent::TOPIC_PREFIX;

    public static function ownFolder(Agent $agent): string
    {
        return self::OWN_FOLDER_PREFIX.$agent->getSlug();
    }

    /**
     * @return list<array{ownerId: int, groupKey: string}>
     */
    public static function scopes(Agent $agent, AgentDefinition $definition): array
    {
        $scopes = [];
        if ($definition->ownFolderEnabled()) {
            $scopes[] = ['ownerId' => $agent->getOwnerId(), 'groupKey' => self::ownFolder($agent)];
        }
        foreach ($definition->knowledgeFolders() as $folderId) {
            $parsed = KnowledgeFolderKind::parseId($folderId);
            if (null === $parsed) {
                continue;
            }
            $scope = ['ownerId' => $parsed[0], 'groupKey' => $parsed[1]];
            if (!in_array($scope, $scopes, true)) {
                $scopes[] = $scope;
            }
        }

        return $scopes;
    }

    /**
     * Folders the assistant's *owner* contributes. A recipient of the shared
     * assistant may search these; folders that belong to a third user stay
     * behind that user's own grants (sharing an assistant never forwards a
     * folder its owner merely has "use" on).
     *
     * @return list<string>
     */
    public static function ownerFolders(Agent $agent, AgentDefinition $definition): array
    {
        $out = [];
        foreach (self::scopes($agent, $definition) as $scope) {
            if ($scope['ownerId'] === $agent->getOwnerId()) {
                $out[] = $scope['groupKey'];
            }
        }

        return $out;
    }
}
