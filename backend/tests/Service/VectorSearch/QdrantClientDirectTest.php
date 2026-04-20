<?php

declare(strict_types=1);

namespace App\Tests\Service\VectorSearch;

use App\Service\VectorSearch\QdrantClientDirect;
use App\Service\VectorSearch\QdrantPointId;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for QdrantClientDirect — talks to Qdrant REST API directly.
 *
 * These tests use a callback-style MockHttpClient that routes by URL path,
 * not a fixed response list. That keeps tests robust against future internal
 * optimisations (parallel index creation, extra health probes, caching, …)
 * that change the exact sequence of HTTP calls but not the observable
 * contract for any given endpoint.
 */
final class QdrantClientDirectTest extends TestCase
{
    private const QDRANT_URL = 'http://localhost:6333';

    /**
     * Build a QdrantClientDirect backed by a route-based MockHttpClient.
     *
     * Callers that want to inspect the raw HTTP calls made by the client
     * can pass an empty array in by reference as `$calls`; it will be
     * populated with one entry per request.
     *
     * @param array<string, callable(array{method: string, path: string, query: string, body: ?string}):MockResponse> $routes Map of path-suffix => responder callback
     * @param list<array{method: string, path: string, query: string, body: ?string}>                                 $calls
     */
    private function buildClient(array $routes, array &$calls = []): QdrantClientDirect
    {
        $factory = function (string $method, string $url, array $options) use ($routes, &$calls): MockResponse {
            $path = (string) (parse_url($url, \PHP_URL_PATH) ?? '');
            $query = (string) (parse_url($url, \PHP_URL_QUERY) ?? '');
            $body = isset($options['body']) && is_string($options['body']) ? $options['body'] : null;
            $call = ['method' => $method, 'path' => $path, 'query' => $query, 'body' => $body];
            $calls[] = $call;

            foreach ($routes as $suffix => $responder) {
                if (str_ends_with($path, $suffix)) {
                    return $responder($call);
                }
            }

            return new MockResponse('No route matched '.$method.' '.$path, ['http_code' => 599]);
        };

        return new QdrantClientDirect(
            httpClient: new MockHttpClient($factory),
            qdrantUrl: self::QDRANT_URL,
            logger: new NullLogger(),
        );
    }

    private function okEmpty(): MockResponse
    {
        return new MockResponse(
            json_encode(['result' => ['status' => 'completed'], 'status' => 'ok']),
            ['http_code' => 200],
        );
    }

    public function testUpsertMemoryIssuesAtomicBatchDeleteThenUpsert(): void
    {
        $calls = [];
        $client = $this->buildClient([
            // ensureMemoriesCollection: GET collection exists
            '/collections/user_memories' => fn () => new MockResponse(
                json_encode(['result' => ['status' => 'green']]),
                ['http_code' => 200]
            ),
            // idempotent payload-index creation (any number of calls)
            '/collections/user_memories/index' => fn () => $this->okEmpty(),
            // the batch endpoint — the heart of upsertMemory
            '/collections/user_memories/points/batch' => fn () => new MockResponse(
                json_encode(['result' => [['status' => 'completed'], ['status' => 'completed']], 'status' => 'ok']),
                ['http_code' => 200]
            ),
        ], $calls);

        $client->upsertMemory(
            pointId: 'mem_1_123',
            vector: array_fill(0, 1024, 0.5),
            payload: ['user_id' => 1, 'category' => 'test', 'key' => 'k', 'value' => 'v', 'source' => 'user_created', 'active' => true],
        );

        $batchCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_ends_with($c['path'], '/points/batch'),
        ));
        $this->assertCount(1, $batchCalls, 'upsertMemory must make exactly one batch request, not a sequence of delete+put');
        $this->assertStringContainsString('wait=true', $batchCalls[0]['query']);

        $body = json_decode((string) $batchCalls[0]['body'], true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('operations', $body);
        $this->assertCount(2, $body['operations']);
        $this->assertArrayHasKey('delete', $body['operations'][0]);
        $this->assertArrayHasKey('upsert', $body['operations'][1]);
        $this->assertSame(
            ['must' => [['key' => '_point_id', 'match' => ['value' => 'mem_1_123']]]],
            $body['operations'][0]['delete']['filter'],
            'delete must be scoped by `_point_id` payload filter, not by primary ID',
        );

        $upsertedPoint = $body['operations'][1]['upsert']['points'][0];
        $this->assertSame(QdrantPointId::uuidFor('mem_1_123'), $upsertedPoint['id']);
        $this->assertSame('mem_1_123', $upsertedPoint['payload']['_point_id']);
    }

    public function testGetMemoryUsesPointIdPayloadFilterNotPrimaryKey(): void
    {
        $calls = [];
        $client = $this->buildClient([
            '/collections/user_memories/points/scroll' => fn () => new MockResponse(
                json_encode(['result' => ['points' => [], 'next_page_offset' => null]]),
                ['http_code' => 200],
            ),
        ], $calls);

        $client->getMemory('mem_1_123');

        $scrollCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_ends_with($c['path'], '/points/scroll'),
        ));
        $this->assertCount(1, $scrollCalls);

        $body = json_decode((string) $scrollCalls[0]['body'], true);
        $this->assertArrayNotHasKey('ids', $body, 'getMemory must not lookup by primary ID');
        $this->assertArrayHasKey('filter', $body);
        $this->assertSame('_point_id', $body['filter']['must'][0]['key']);
        $this->assertSame('mem_1_123', $body['filter']['must'][0]['match']['value']);
    }

    public function testGetMemoryReturnsPayloadWhenFound(): void
    {
        $client = $this->buildClient([
            '/collections/user_memories/points/scroll' => fn () => new MockResponse(
                json_encode([
                    'result' => [
                        'points' => [[
                            'id' => 'anything',
                            'payload' => [
                                '_point_id' => 'mem_1_123',
                                'user_id' => 1,
                                'category' => 'test',
                                'key' => 'k',
                                'value' => 'v',
                            ],
                        ]],
                        'next_page_offset' => null,
                    ],
                ]),
                ['http_code' => 200],
            ),
        ]);

        $result = $client->getMemory('mem_1_123');

        $this->assertIsArray($result);
        $this->assertSame(1, $result['user_id']);
        $this->assertSame('test', $result['category']);
    }

    public function testGetMemoryReturnsNullWhenNotFound(): void
    {
        $client = $this->buildClient([
            '/collections/user_memories/points/scroll' => fn () => new MockResponse(
                json_encode(['result' => ['points' => [], 'next_page_offset' => null]]),
                ['http_code' => 200],
            ),
        ]);

        $this->assertNull($client->getMemory('mem_1_missing'));
    }

    public function testDeleteMemoryUsesPointIdPayloadFilterNotPrimaryKey(): void
    {
        $calls = [];
        $client = $this->buildClient([
            '/collections/user_memories/points/delete' => fn () => $this->okEmpty(),
        ], $calls);

        $client->deleteMemory('mem_1_123');

        $deleteCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_ends_with($c['path'], '/points/delete'),
        ));
        $this->assertCount(1, $deleteCalls);
        $this->assertStringContainsString('wait=true', $deleteCalls[0]['query']);

        $body = json_decode((string) $deleteCalls[0]['body'], true);
        $this->assertArrayNotHasKey('points', $body, 'deleteMemory must not send primary-ID list; that breaks legacy int-keyed points');
        $this->assertArrayHasKey('filter', $body);
        $this->assertSame('_point_id', $body['filter']['must'][0]['key']);
        $this->assertSame('mem_1_123', $body['filter']['must'][0]['match']['value']);
    }

    /**
     * Regression test for the scroll-dedup safety net. Until the one-shot
     * legacy migration runs, the same logical memory can exist under BOTH
     * an integer primary and a UUID primary. Scroll must not show both.
     */
    public function testScrollMemoriesDedupesDuplicateLogicalPoints(): void
    {
        $client = $this->buildClient([
            '/collections/user_memories/points/scroll' => fn () => new MockResponse(
                json_encode([
                    'result' => [
                        'points' => [
                            [
                                'id' => 82402287705672322, // legacy int
                                'payload' => [
                                    '_point_id' => 'mem_1_dup',
                                    'user_id' => 1,
                                    'category' => 'a',
                                    'key' => 'k',
                                    'value' => 'legacy-copy',
                                    'active' => true,
                                ],
                            ],
                            [
                                'id' => QdrantPointId::uuidFor('mem_1_dup'), // UUID copy
                                'payload' => [
                                    '_point_id' => 'mem_1_dup',
                                    'user_id' => 1,
                                    'category' => 'a',
                                    'key' => 'k',
                                    'value' => 'uuid-copy',
                                    'active' => true,
                                ],
                            ],
                            [
                                'id' => 'different-uuid',
                                'payload' => [
                                    '_point_id' => 'mem_1_other',
                                    'user_id' => 1,
                                    'category' => 'a',
                                    'key' => 'k2',
                                    'value' => 'other',
                                    'active' => true,
                                ],
                            ],
                        ],
                        'next_page_offset' => null,
                    ],
                ]),
                ['http_code' => 200],
            ),
        ]);

        $memories = $client->scrollMemories(userId: 1);
        $logicalIds = array_map(static fn (array $m): string => $m['id'], $memories);

        $this->assertCount(2, $memories, 'duplicate logical points must collapse into one entry');
        $this->assertSame(['mem_1_dup', 'mem_1_other'], $logicalIds);
    }

    public function testSearchMemoriesDedupesAndKeepsHigherScoringCopy(): void
    {
        $client = $this->buildClient([
            '/collections/user_memories/points/search' => fn () => new MockResponse(
                json_encode([
                    'result' => [
                        [
                            'id' => 11111,
                            'score' => 0.72,
                            'payload' => [
                                '_point_id' => 'mem_1_dup',
                                'user_id' => 1, 'category' => 'a', 'key' => 'k', 'value' => 'legacy', 'active' => true,
                            ],
                        ],
                        [
                            'id' => QdrantPointId::uuidFor('mem_1_dup'),
                            'score' => 0.91,
                            'payload' => [
                                '_point_id' => 'mem_1_dup',
                                'user_id' => 1, 'category' => 'a', 'key' => 'k', 'value' => 'fresh-uuid', 'active' => true,
                            ],
                        ],
                    ],
                ]),
                ['http_code' => 200],
            ),
        ]);

        $results = $client->searchMemories(
            queryVector: array_fill(0, 1024, 0.5),
            userId: 1,
            category: null,
            limit: 5,
            minScore: 0.7,
        );

        $this->assertCount(1, $results);
        $this->assertSame('mem_1_dup', $results[0]['id']);
        $this->assertSame(0.91, $results[0]['score'], 'dedup must keep the higher-scoring copy');
        $this->assertSame('fresh-uuid', $results[0]['payload']['value']);
    }

    public function testUpsertDocumentIssuesAtomicBatchDeleteThenUpsert(): void
    {
        $calls = [];
        $client = $this->buildClient([
            '/collections/user_documents' => fn () => new MockResponse(
                json_encode(['result' => ['status' => 'green']]),
                ['http_code' => 200]
            ),
            '/collections/user_documents/index' => fn () => $this->okEmpty(),
            '/collections/user_documents/points/batch' => fn () => new MockResponse(
                json_encode(['result' => [['status' => 'completed'], ['status' => 'completed']], 'status' => 'ok']),
                ['http_code' => 200]
            ),
        ], $calls);

        $client->upsertDocument(
            pointId: 'doc_1_42_0',
            vector: array_fill(0, 1024, 0.1),
            payload: ['user_id' => 1, 'file_id' => 42, 'group_key' => 'g', 'text' => 't'],
        );

        $batchCalls = array_values(array_filter(
            $calls,
            static fn (array $c): bool => str_ends_with($c['path'], '/points/batch'),
        ));
        $this->assertCount(1, $batchCalls);

        $body = json_decode((string) $batchCalls[0]['body'], true);
        $this->assertArrayHasKey('delete', $body['operations'][0]);
        $this->assertArrayHasKey('upsert', $body['operations'][1]);
        $this->assertSame(
            'doc_1_42_0',
            $body['operations'][0]['delete']['filter']['must'][0]['match']['value'],
        );
    }

    public function testHealthCheckReturnsTrueOn200(): void
    {
        $client = $this->buildClient([
            '/healthz' => fn () => new MockResponse('', ['http_code' => 200]),
        ]);

        $this->assertTrue($client->healthCheck());
    }

    public function testHealthCheckReturnsFalseOn503(): void
    {
        $client = $this->buildClient([
            '/healthz' => fn () => new MockResponse('Service Unavailable', ['http_code' => 503]),
        ]);

        $this->assertFalse($client->healthCheck());
    }

    public function testHealthCheckReturnsFalseWhenNotConfigured(): void
    {
        $client = new QdrantClientDirect(
            httpClient: new MockHttpClient([]),
            qdrantUrl: '',
            logger: new NullLogger(),
        );

        $this->assertFalse($client->healthCheck());
        $this->assertFalse($client->isAvailable());
    }

    public function testCollectionNameGettersExposeConfiguredNames(): void
    {
        $client = new QdrantClientDirect(
            httpClient: new MockHttpClient([]),
            qdrantUrl: 'http://x',
            logger: new NullLogger(),
            memoriesCollection: 'custom_mem',
            documentsCollection: 'custom_doc',
        );

        $this->assertSame('custom_mem', $client->getMemoriesCollection());
        $this->assertSame('custom_doc', $client->getDocumentsCollection());
        $this->assertSame('http://x', $client->getQdrantUrl());
    }
}
