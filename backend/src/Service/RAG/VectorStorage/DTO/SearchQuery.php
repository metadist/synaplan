<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage\DTO;

use App\Service\Iam\Exception\EmptyRagScopeException;

final readonly class SearchQuery
{
    /** @var list<RagScope> */
    public array $scopes;

    /**
     * @param float[]             $vector
     * @param list<RagScope>|null $scopes null = one own-scope from userId + groupKey
     */
    public function __construct(
        public int $userId,
        public array $vector,
        public ?string $groupKey = null,
        public int $limit = 10,
        public float $minScore = 0.3,
        ?array $scopes = null,
    ) {
        $resolved = $scopes ?? [new RagScope($userId, $groupKey)];
        if ([] === $resolved) {
            throw new EmptyRagScopeException();
        }
        $this->scopes = $resolved;
    }

    /**
     * Today's single-owner query: one scope, same SQL/JSON as before S2.
     *
     * @param float[] $vector
     */
    public static function own(
        int $userId,
        array $vector,
        ?string $groupKey = null,
        int $limit = 10,
        float $minScore = 0.3,
    ): self {
        return new self(
            userId: $userId,
            vector: $vector,
            groupKey: $groupKey,
            limit: $limit,
            minScore: $minScore,
            scopes: [new RagScope($userId, $groupKey)],
        );
    }

    /**
     * True when the filter must match the pre-S2 shape (one own scope, no file list).
     */
    public function isLegacyOwnFilter(): bool
    {
        return 1 === count($this->scopes) && $this->scopes[0]->isLegacyOwn($this->userId, $this->groupKey);
    }
}
