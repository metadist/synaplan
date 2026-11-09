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
     * @param array<string, int|null>                                          $modelIds         capability → BMODELS BID
     * @param list<array{ownerId: int, groupKey: string, fileIds?: list<int>}> $ragScopes
     * @param array<string, mixed>                                             $toolFlags
     * @param list<string>|null                                                $skillAllow       null = unrestricted
     * @param list<string>|null                                                $skillDeny
     * @param array<string, mixed>                                             $parameters
     * @param list<string>                                                     $notes            resolver diagnostics (model_fallback:chat, …)
     * @param int|null                                                         $viewerId         the user this profile was resolved for
     * @param bool                                                             $includeUserFiles whether the viewer's own files are searched alongside the assistant's folders
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
     * A profile that was stored as JSON and has to be an object again.
     * Runners read scopes and instructions from the instance.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $modelIds = [];
        foreach (is_array($data['modelIds'] ?? null) ? $data['modelIds'] : [] as $capability => $modelId) {
            if (is_string($capability)) {
                $modelIds[$capability] = is_int($modelId) ? $modelId : null;
            }
        }

        return new self(
            promptId: self::intOrNull($data['promptId'] ?? null),
            promptTopic: is_string($data['promptTopic'] ?? null) ? $data['promptTopic'] : '',
            systemPrompt: is_string($data['systemPrompt'] ?? null) ? $data['systemPrompt'] : null,
            modelIds: $modelIds,
            ragScopes: self::ragScopes($data['ragScopes'] ?? null),
            toolFlags: is_array($data['toolFlags'] ?? null) ? $data['toolFlags'] : [],
            skillAllow: self::stringList($data['skillAllow'] ?? null),
            skillDeny: self::stringList($data['skillDeny'] ?? null),
            parameters: is_array($data['parameters'] ?? null) ? $data['parameters'] : [],
            agentId: self::intOrNull($data['agentId'] ?? null),
            agentVersionId: self::intOrNull($data['agentVersionId'] ?? null),
            notes: self::stringList($data['notes'] ?? null) ?? [],
            ragLimit: self::intOrNull($data['ragLimit'] ?? null),
            ragMinScore: is_int($data['ragMinScore'] ?? null) || is_float($data['ragMinScore'] ?? null) ? (float) $data['ragMinScore'] : null,
            viewerId: self::intOrNull($data['viewerId'] ?? null),
            includeUserFiles: true === ($data['includeUserFiles'] ?? false),
        );
    }

    /**
     * @return list<array{ownerId: int, groupKey: string, fileIds?: list<int>}>
     */
    private static function ragScopes(mixed $scopes): array
    {
        if (!is_array($scopes)) {
            return [];
        }
        $parsed = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope) || !is_int($scope['ownerId'] ?? null) || !is_string($scope['groupKey'] ?? null)) {
                continue;
            }
            $entry = ['ownerId' => $scope['ownerId'], 'groupKey' => $scope['groupKey']];
            $fileIds = self::intList($scope['fileIds'] ?? null);
            if (null !== $fileIds) {
                $entry['fileIds'] = $fileIds;
            }
            $parsed[] = $entry;
        }

        return $parsed;
    }

    /**
     * @return list<string>|null
     */
    private static function stringList(mixed $values): ?array
    {
        if (!is_array($values)) {
            return null;
        }
        $parsed = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $parsed[] = $value;
            }
        }

        return $parsed;
    }

    /**
     * @return list<int>|null
     */
    private static function intList(mixed $values): ?array
    {
        if (!is_array($values)) {
            return null;
        }
        $parsed = [];
        foreach ($values as $value) {
            if (is_int($value)) {
                $parsed[] = $value;
            }
        }

        return $parsed;
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
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
