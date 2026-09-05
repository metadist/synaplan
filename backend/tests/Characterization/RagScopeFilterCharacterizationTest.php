<?php

declare(strict_types=1);

namespace App\Tests\Characterization;

use App\Service\Iam\Exception\EmptyRagScopeException;
use App\Service\RAG\VectorStorage\DTO\RagScope;
use App\Service\RAG\VectorStorage\DTO\SearchQuery;
use App\Service\RAG\VectorStorage\RagScopeFilter;
use PHPUnit\Framework\TestCase;

final class RagScopeFilterCharacterizationTest extends TestCase
{
    public function testOwnOnlyMatchesLegacyFilter(): void
    {
        $query = SearchQuery::own(7, [0.1, 0.2], 'playbook');

        self::assertSame(
            [
                'sql' => 'r.BUID = :userId AND r.BGROUPKEY = :groupKey',
                'params' => ['userId' => 7, 'groupKey' => 'playbook'],
            ],
            RagScopeFilter::mariaDbWhere($query),
        );
        self::assertSame(
            [
                'must' => [
                    ['key' => 'user_id', 'match' => ['value' => 7]],
                    ['key' => 'group_key', 'match' => ['value' => 'playbook']],
                ],
            ],
            RagScopeFilter::qdrant($query),
        );
    }

    public function testOwnOnlyWithoutFolderMatchesLegacyFilter(): void
    {
        $query = SearchQuery::own(3, [0.0]);

        self::assertSame(
            [
                'sql' => 'r.BUID = :userId',
                'params' => ['userId' => 3],
            ],
            RagScopeFilter::mariaDbWhere($query),
        );
        self::assertSame(
            [
                'must' => [
                    ['key' => 'user_id', 'match' => ['value' => 3]],
                ],
            ],
            RagScopeFilter::qdrant($query),
        );
    }

    public function testManySharesUseShouldAndOr(): void
    {
        $query = new SearchQuery(
            userId: 3,
            vector: [0.0],
            groupKey: null,
            scopes: [
                new RagScope(3, null),
                new RagScope(9, 'sales'),
                new RagScope(9, null, [11, 12]),
            ],
        );

        $maria = RagScopeFilter::mariaDbWhere($query);
        self::assertSame(
            '((r.BUID = :u0) OR (r.BUID = :u1 AND r.BGROUPKEY = :g1) OR (r.BUID = :u2 AND r.BMID IN (11,12)))',
            $maria['sql'],
        );
        self::assertSame(['u0' => 3, 'u1' => 9, 'g1' => 'sales', 'u2' => 9], $maria['params']);

        $qdrant = RagScopeFilter::qdrant($query);
        self::assertArrayHasKey('should', $qdrant);
        self::assertCount(3, $qdrant['should']);
    }

    public function testZeroScopesThrow(): void
    {
        $this->expectException(EmptyRagScopeException::class);
        new SearchQuery(userId: 1, vector: [0.0], scopes: []);
    }
}
