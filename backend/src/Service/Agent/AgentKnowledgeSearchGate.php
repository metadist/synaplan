<?php

declare(strict_types=1);

namespace App\Service\Agent;

use App\Service\Message\WebSearchTopicPolicy;
use App\Service\RAG\VectorStorage\DTO\RagScope;

/**
 * When an assistant declares knowledge, a real question searches it even if
 * the sorter did not pick a RAG intent. A greeting does not: "hello" stays
 * a normal chat and does not pay for an embedding.
 */
final class AgentKnowledgeSearchGate
{
    /**
     * @param list<RagScope>|null $scopes null = not an assistant turn
     */
    public static function shouldSearch(?array $scopes, string $text, bool $alreadySearching): bool
    {
        if ($alreadySearching) {
            return true;
        }
        if (null === $scopes || [] === $scopes || !self::hasKnowledge($scopes)) {
            return false;
        }

        return !WebSearchTopicPolicy::isTrivialConversational($text);
    }

    /**
     * @param list<RagScope> $scopes
     */
    private static function hasKnowledge(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if ([] !== $scope->fileIds) {
                return true;
            }
            // A null group key is "this owner's files" (includeUserFiles).
            // A named group key is a folder. An empty list with neither is not
            // knowledge — callers should not pass that shape.
            if (null === $scope->groupKey || '' !== $scope->groupKey) {
                return true;
            }
        }

        return false;
    }
}
