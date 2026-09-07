<?php

declare(strict_types=1);

namespace App\Service\Runtime;

/**
 * The resolved chat runtime: prompt, models, RAG scopes, tools, skills.
 *
 * Always present on the chat path. The default profile is "the user's
 * defaults"; a pinned assistant is the same object with those fields
 * filled from `agent.v1`. ChatHandler has no `if ($agent)` branch.
 */
final readonly class RuntimeProfile
{
    /**
     * @param array<string, int|null>                     $modelIds   capability → BMODELS BID
     * @param list<array{ownerId: int, groupKey: string}> $ragScopes
     * @param array<string, mixed>                        $toolFlags
     * @param list<string>|null                           $skillAllow null = unrestricted
     * @param list<string>|null                           $skillDeny
     * @param array<string, mixed>                        $parameters
     * @param list<string>                                $notes      resolver diagnostics (model_fallback:chat, …)
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
    ) {
    }

    public function primaryRagGroupKey(): ?string
    {
        if ([] === $this->ragScopes) {
            return null;
        }

        return $this->ragScopes[0]['groupKey'];
    }
}
