<?php

declare(strict_types=1);

namespace App\Tests\Service\VectorSearch;

use App\Service\VectorSearch\LegacyPointIdMigrator;
use App\Service\VectorSearch\QdrantPointId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @see LegacyPointIdMigrator
 */
final class LegacyPointIdMigratorTest extends TestCase
{
    private const COLLECTION = 'user_memories';

    public function testDryRunReportsLegacyPointsWithoutWriting(): void
    {
        $requests = $this->recordingHttpClient([
            '/collections/user_memories/points/scroll' => [
                'method' => 'POST',
                'response' => $this->scrollResponseWith([
                    $this->legacyPoint(82402287705672322, 'mem_152_leg1'),
                    $this->legacyPoint(105733502448967875, 'mem_499_leg2'),
                    $this->canonicalPoint('mem_2_canon1'),
                ]),
            ],
        ]);

        $migrator = new LegacyPointIdMigrator(
            httpClient: $requests['client'],
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $phases = [];
        $report = $migrator->migrateCollection(
            collection: self::COLLECTION,
            apply: false,
            limit: 0,
            onPoint: static function (string $phase, string $logicalId) use (&$phases): void {
                $phases[] = [$phase, $logicalId];
            },
        );

        $this->assertSame(3, $report->scanned);
        $this->assertSame(2, $report->legacy, 'one point is already canonically keyed and should not count as legacy');
        $this->assertSame(2, $report->migrated, 'dry-run counts planned migrations');
        $this->assertSame(0, $report->errors);

        $this->assertSame([
            ['would-migrate', 'mem_152_leg1'],
            ['would-migrate', 'mem_499_leg2'],
        ], $phases);

        // Dry run must NOT issue any batch/delete/upsert writes.
        $this->assertSame(
            ['/collections/user_memories/points/scroll'],
            array_column($requests['calls'], 'path'),
        );
    }

    public function testApplyRekeysLegacyPointsViaBatchUpsertThenDelete(): void
    {
        $requests = $this->recordingHttpClient([
            '/collections/user_memories/points/scroll' => [
                'method' => 'POST',
                'response' => $this->scrollResponseWith([
                    $this->legacyPoint(82402287705672322, 'mem_152_leg1'),
                ]),
            ],
            '/collections/user_memories/points/batch' => [
                'method' => 'POST',
                'response' => new MockResponse(
                    json_encode(['result' => [['status' => 'completed'], ['status' => 'completed']], 'status' => 'ok']),
                    ['http_code' => 200]
                ),
            ],
        ]);

        $migrator = new LegacyPointIdMigrator(
            httpClient: $requests['client'],
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $report = $migrator->migrateCollection(
            collection: self::COLLECTION,
            apply: true,
            limit: 0,
        );

        $this->assertSame(1, $report->migrated);
        $this->assertSame(0, $report->errors);

        $batchCalls = array_values(array_filter(
            $requests['calls'],
            static fn (array $c): bool => str_ends_with((string) $c['path'], '/points/batch'),
        ));
        $this->assertCount(1, $batchCalls, 'each legacy point produces exactly one batch request');

        $body = json_decode((string) $batchCalls[0]['body'], true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('operations', $body);
        $ops = $body['operations'];

        // Upsert first (so the canonical copy exists before we drop the legacy ID),
        // then delete the legacy primary ID.
        $this->assertSame(
            ['upsert', 'delete'],
            [array_key_first($ops[0]), array_key_first($ops[1])],
            'batch must upsert before deleting so a batch-level abort never leaves zero points'
        );

        $upsertedPoint = $ops[0]['upsert']['points'][0];
        $this->assertSame(QdrantPointId::uuidFor('mem_152_leg1'), $upsertedPoint['id']);
        $this->assertSame('mem_152_leg1', $upsertedPoint['payload']['_point_id']);

        $this->assertSame([82402287705672322], $ops[1]['delete']['points']);
    }

    public function testLimitCapsMigrationCountExactly(): void
    {
        $requests = $this->recordingHttpClient([
            '/collections/user_memories/points/scroll' => [
                'method' => 'POST',
                'response' => $this->scrollResponseWith([
                    $this->legacyPoint(1, 'mem_1_a'),
                    $this->legacyPoint(2, 'mem_1_b'),
                    $this->legacyPoint(3, 'mem_1_c'),
                    $this->legacyPoint(4, 'mem_1_d'),
                    $this->legacyPoint(5, 'mem_1_e'),
                ]),
            ],
            '/collections/user_memories/points/batch' => [
                'method' => 'POST',
                'response' => new MockResponse(
                    json_encode(['result' => [['status' => 'completed'], ['status' => 'completed']], 'status' => 'ok']),
                    ['http_code' => 200]
                ),
            ],
        ]);

        $migrator = new LegacyPointIdMigrator(
            httpClient: $requests['client'],
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $report = $migrator->migrateCollection(
            collection: self::COLLECTION,
            apply: true,
            limit: 2,
        );

        $this->assertSame(2, $report->migrated);
        $batchCalls = array_values(array_filter(
            $requests['calls'],
            static fn (array $c): bool => str_ends_with((string) $c['path'], '/points/batch'),
        ));
        $this->assertCount(2, $batchCalls, 'limit must stop the rekey loop exactly at the cap');
    }

    public function testSkipsPointsWithoutPointIdPayload(): void
    {
        $requests = $this->recordingHttpClient([
            '/collections/user_memories/points/scroll' => [
                'method' => 'POST',
                'response' => $this->scrollResponseWith([
                    ['id' => 999, 'payload' => ['user_id' => 1], 'vector' => [0.1, 0.2]],
                ]),
            ],
        ]);

        $migrator = new LegacyPointIdMigrator(
            httpClient: $requests['client'],
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $phases = [];
        $report = $migrator->migrateCollection(
            collection: self::COLLECTION,
            apply: true,
            limit: 0,
            onPoint: static function (string $phase) use (&$phases): void {
                $phases[] = $phase;
            },
        );

        $this->assertSame(0, $report->legacy);
        $this->assertSame(0, $report->migrated);
        $this->assertSame(['skip-missing-payload'], $phases);
    }

    /**
     * Progress guard: if Qdrant returns a `next_page_offset` equal to the
     * one we just sent, the cursor is stuck on the same page and the
     * guard must abort on the very next iteration.
     */
    public function testAbortsWhenScrollOffsetDoesNotAdvance(): void
    {
        $callCount = 0;
        $factory = function (string $method, string $url, array $options) use (&$callCount): MockResponse {
            ++$callCount;

            // Always return the same next_page_offset → loop must break
            // the moment we ask for that offset and get it back unchanged.
            return new MockResponse(
                json_encode([
                    'result' => [
                        'points' => [$this->legacyPoint(1, 'mem_loop_bug')],
                        'next_page_offset' => 'stuck-offset-xyz',
                    ],
                    'status' => 'ok',
                ]),
                ['http_code' => 200]
            );
        };

        $migrator = new LegacyPointIdMigrator(
            httpClient: new MockHttpClient($factory),
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $report = $migrator->migrateCollection(
            collection: self::COLLECTION,
            apply: false,
            limit: 0,
        );

        // Exactly 2 scroll calls expected:
        //   Iter 1: offset=null              → returns next=stuck        (no stuckness yet)
        //   Iter 2: offset=stuck             → returns next=stuck        (guard fires, break)
        // A third call would indicate the guard is broken.
        $this->assertSame(2, $callCount, 'progress guard must abort on the first iteration where next_page_offset equals the requested offset');
        $this->assertGreaterThan(0, $report->scanned);
    }

    /**
     * @return array{id: int, payload: array<string, mixed>, vector: list<float>}
     */
    private function legacyPoint(int $id, string $logicalId): array
    {
        return [
            'id' => $id,
            'payload' => [
                '_point_id' => $logicalId,
                'user_id' => 1,
                'key' => 'test',
                'value' => 'test',
                'category' => 'personal',
                'source' => 'user_created',
                'active' => true,
                'created' => 0,
                'updated' => 0,
            ],
            'vector' => array_fill(0, 4, 0.1), // small vector, we don't care about dim here
        ];
    }

    /**
     * A point already keyed under its canonical UUIDv5 — migration must leave it alone.
     *
     * @return array{id: string, payload: array<string, mixed>, vector: list<float>}
     */
    private function canonicalPoint(string $logicalId): array
    {
        return [
            'id' => QdrantPointId::uuidFor($logicalId),
            'payload' => [
                '_point_id' => $logicalId,
                'user_id' => 1,
                'key' => 'x',
                'value' => 'y',
                'category' => 'personal',
                'source' => 'user_created',
                'active' => true,
                'created' => 0,
                'updated' => 0,
            ],
            'vector' => array_fill(0, 4, 0.1),
        ];
    }

    /**
     * @param list<array<string, mixed>> $points
     */
    private function scrollResponseWith(array $points): MockResponse
    {
        return new MockResponse(
            json_encode([
                'result' => [
                    'points' => $points,
                    'next_page_offset' => null,
                ],
                'status' => 'ok',
            ]),
            ['http_code' => 200]
        );
    }

    /**
     * Build a MockHttpClient whose response is picked by URL suffix, and
     * capture every call for post-hoc assertions. Returns both the client
     * and a `&$calls` array by reference via the returned tuple.
     *
     * @param array<string, array{method: string, response: MockResponse}> $routes Map of URL-path-suffix => response spec
     *
     * @return array{client: MockHttpClient, calls: list<array{method: string, path: string, body: ?string}>}
     */
    private function recordingHttpClient(array $routes): array
    {
        $calls = [];

        $factory = function (string $method, string $url, array $options) use ($routes, &$calls): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $calls[] = [
                'method' => $method,
                'path' => $path,
                'body' => isset($options['body']) && is_string($options['body']) ? $options['body'] : null,
            ];

            foreach ($routes as $suffix => $spec) {
                if (str_ends_with($path, $suffix)) {
                    return clone $spec['response'];
                }
            }

            return new MockResponse('Unexpected URL in test: '.$url, ['http_code' => 500]);
        };

        return [
            'client' => new MockHttpClient($factory),
            'calls' => &$calls,
        ];
    }
}
