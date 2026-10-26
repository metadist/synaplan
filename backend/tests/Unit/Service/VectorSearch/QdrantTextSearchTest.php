<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\VectorSearch;

use App\Service\VectorSearch\QdrantTextSearch;
use PHPUnit\Framework\TestCase;

final class QdrantTextSearchTest extends TestCase
{
    private const SCOPE = ['must' => [['key' => 'user_id', 'match' => ['value' => 4]]]];

    public function testTokensMatchTheIndexTokenizer(): void
    {
        self::assertSame(['art', '17', 'dsgvo', 'größe'], QdrantTextSearch::tokens('Art. 17 DSGVO, a Größe!'));
        self::assertSame([], QdrantTextSearch::tokens('  ?! '));
        self::assertSame([], QdrantTextSearch::tokens(str_repeat('x', 41)));
    }

    public function testRareWordsPickTheCandidatesAndWeighMore(): void
    {
        $requests = [];
        $post = function (string $endpoint, array $body) use (&$requests): array {
            $requests[] = [$endpoint, $body];
            if ('count' === $endpoint) {
                $words = $this->words($body['filter']);

                return ['result' => ['count' => match ($words) {
                    [] => 2000,
                    ['what'] => 900,
                    ['the'] => 1900,
                    ['17'] => 3,
                    default => 0,
                }]];
            }

            return ['result' => ['points' => [
                ['id' => 'uuid-1', 'score' => 6.0, 'payload' => ['_point_id' => 'doc_1', 'text' => 'Art. 17']],
                ['id' => 'uuid-2', 'payload' => ['text' => 'no score']],
            ]]];
        };

        $rows = QdrantTextSearch::search('What the 17 xyz', self::SCOPE, 5, $post);

        [$endpoint, $query] = end($requests);
        self::assertSame('query', $endpoint);
        self::assertSame(['17'], $this->words($query['prefetch']['filter']), 'a word in most chunks must not pick the candidates');
        $weights = array_column(array_column($query['query']['formula']['sum'], 'mult'), 0);
        self::assertGreaterThan($weights[1], $weights[0], 'the rare word weighs more');
        self::assertCount(2, $weights, 'a word no chunk has, and one in most chunks, is dropped');
        self::assertSame('doc_1', $rows[0]['id']);
        self::assertGreaterThan(0.0, $rows[0]['score']);
        self::assertLessThanOrEqual(1.0, $rows[0]['score']);
        self::assertSame(0.0, $rows[1]['score']);
    }

    public function testNoMatchingWordSkipsTheQuery(): void
    {
        $endpoints = [];
        $post = static function (string $endpoint, array $body) use (&$endpoints): array {
            $endpoints[] = $endpoint;

            return ['result' => ['count' => isset($body['filter']['must'][1]) ? 0 : 10]];
        };

        self::assertSame([], QdrantTextSearch::search('nothing here', self::SCOPE, 5, $post));
        self::assertNotContains('query', $endpoints);
    }

    public function testAnEmptyScopeSkipsEverything(): void
    {
        $post = static fn (string $endpoint, array $body): array => ['result' => ['count' => 0]];

        self::assertSame([], QdrantTextSearch::search('Art. 17', self::SCOPE, 5, $post));
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return list<string>
     */
    private function words(array $filter): array
    {
        $text = $filter['must'][1]['should'] ?? [];

        return array_map(static fn (array $condition): string => $condition['match']['text'], $text);
    }
}
