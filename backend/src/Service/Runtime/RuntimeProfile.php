<?php

declare(strict_types=1);

namespace App\Service\Runtime;

use App\Service\RAG\RagScopeResolver;

/**
 * The resolved chat runtime: prompt, models, RAG scopes, tools, skills.
 *
 * Resolved once by {@see \App\Service\Agent\AgentPinResolver} when a turn
 * is pinned to an assistant and handed down as `runtime_profile`. Consumers
 * read RAG scope, limit and score from this object; nothing is copied into
 * scalar classification/option keys. Unpinned turns carry no profile and
 * follow the classifier path unchanged.
 */
final readonly class RuntimeProfile
{
    /**
     * @param array<string, int|null>                     $modelIds         capability → BMODELS BID
     * @param list<array{ownerId: int, groupKey: string}> $ragScopes
     * @param array<string, mixed>                        $toolFlags
     * @param list<string>|null                           $skillAllow       null = unrestricted
     * @param list<string>|null                           $skillDeny
     * @param array<string, mixed>                        $parameters
     * @param list<string>                                $notes            resolver diagnostics (model_fallback:chat, …)
     * @param int|null                                    $viewerId         the user this profile was resolved for
     * @param bool                                        $includeUserFiles whether the viewer's own files are searched alongside the assistant's folders
     */
    public function __construct(
        public ?int $promptId,
        public string $promptTopic,
        public ?string $systemPrompt,
        public array $modelIds,
        public array $ragScopes,
        public array $toolFlags,
        public ?array $skillAllow,
        public ?array $skillDeny,
        public array $parameters,
        public ?int $agentId = null,
        public ?int $agentVersionId = null,
        public array $notes = [],
        public ?int $ragLimit = null,
        public ?float $ragMinScore = null,
        public ?int $viewerId = null,
        public bool $includeUserFiles = false,
    ) {
    }

    /**
     * The first RAG scope as the group-key string the search path consumes.
     *
     * A scope owned by someone other than the viewer (a shared assistant's
     * knowledge) is emitted in the `shared:{ownerId}:{folder}` form, which
     * {@see RagScopeResolver} checks against the viewer's
     * grants before searching the owner's files.
     */
    public function primaryRagGroupKey(): ?string
    {
        if ([] === $this->ragScopes) {
            return null;
        }

        $scope = $this->ragScopes[0];
        if (null !== $this->viewerId && $scope['ownerId'] !== $this->viewerId) {
            return RagScopeResolver::sharedPickerKey($scope['ownerId'], $scope['groupKey']);
        }

        return $scope['groupKey'];
    }
}
