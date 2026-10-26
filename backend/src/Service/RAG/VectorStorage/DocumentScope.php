<?php

declare(strict_types=1);

namespace App\Service\RAG\VectorStorage;

use App\Service\RAG\VectorStorage\DTO\SearchQuery;

/**
 * The Qdrant filter a document search uses, for vectors and for keywords.
 *
 * Both legs call this, so a keyword query cannot see a wider set than the
 * vector query built from the same owner, folder and file list.
 */
final class DocumentScope
{
    /**
     * @return array<string, mixed>
     */
    public static function filter(int $userId, ?string $groupKey, ?SearchQuery $query): array
    {
        if (null !== $query && !$query->isLegacyOwnFilter()) {
            return RagScopeFilter::qdrant($query);
        }

        return RagScopeFilter::qdrant(SearchQuery::own($userId, [], $groupKey));
    }
}
