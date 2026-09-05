<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\RAG;

use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\RagScopeFilter;
use PHPUnit\Framework\TestCase;

/**
 * C2 for the Qdrant filter document: own-only stays `{must: [user_id…]}`;
 * shared scopes use `should` of must-clauses that each still name an owner.
 */
final class QdrantKnowledgeIsolationTest extends TestCase
{
    public function testOwnOnlyNeverUsesShould(): void
    {
        $filter = RagScopeFilter::qdrant(SearchQuery::own(4, [0.1], 'inbox'));

        self::assertArrayHasKey('must', $filter);
        self::assertArrayNotHasKey('should', $filter);
        self::assertSame(4, $filter['must'][0]['match']['value']);
    }

    public function testSharedScopesEachNameAnOwner(): void
    {
        $query = new SearchQuery(
            userId: 4,
            vector: [0.0],
            scopes: [
                new RagScope(4, null),
                new RagScope(9, 'sales'),
            ],
        );

        $filter = RagScopeFilter::qdrant($query);
        self::assertArrayHasKey('should', $filter);
        foreach ($filter['should'] as $clause) {
            self::assertArrayHasKey('must', $clause);
            self::assertSame('user_id', $clause['must'][0]['key']);
        }
    }
}
