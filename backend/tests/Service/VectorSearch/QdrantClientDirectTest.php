<?php

declare(strict_types=1);

namespace App\Tests\Service\VectorSearch;

use App\Service\VectorSearch\QdrantClientDirect;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests for QdrantClientDirect — talks to Qdrant REST API directly.
 *
 * Uses mock HTTP responses matching Qdrant's native API format.
 */
final class QdrantClientDirectTest extends TestCase
{
    /**
     * @param array<MockResponse> $responses
     */
    private function createClient(array $responses): QdrantClientDirect
    {
        $mockClient = new MockHttpClient($responses);

        return new QdrantClientDirect(
            httpClient: $mockClient,
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );
    }

    public function testUpsertMemorySuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            // ensureMemoriesCollection -> GET /collections/user_memories
            new MockResponse(json_encode(['result' => ['status' => 'green']]), ['http_code' => 200]),
            // ensurePayloadIndexes -> PUT /collections/user_memories/index  x4
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            // upsertMemory -> pre-upsert POST /collections/user_memories/points/delete?wait=true
            new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]),
            // upsertMemory -> PUT /collections/user_memories/points?wait=true
            new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);

        $client->upsertMemory(
            pointId: 'mem_1_123',
            vector: array_fill(0, 1024, 0.5),
            payload: [
                'user_id' => 1,
                'category' => 'test',
                'key' => 'test_key',
                'value' => 'test_value',
                'source' => 'test',
                'created' => time(),
                'updated' => time(),
                'active' => true,
            ]
        );
    }

    public function testGetMemorySuccess(): void
    {
        $responses = [
            // getMemory -> POST /collections/user_memories/points/scroll
            // (filter on `_point_id` payload, matches both legacy int + UUID keys)
            new MockResponse(json_encode([
                'result' => [
                    'points' => [
                        [
                            'id' => 'some-uuid',
                            'payload' => [
                                '_point_id' => 'mem_1_123',
                                'user_id' => 1,
                                'category' => 'test',
                                'key' => 'test_key',
                                'value' => 'test_value',
                            ],
                        ],
                    ],
                    'next_page_offset' => null,
                ],
            ]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $result = $client->getMemory('mem_1_123');

        $this->assertIsArray($result);
        $this->assertEquals(1, $result['user_id']);
        $this->assertEquals('test', $result['category']);
    }

    public function testGetMemoryNotFound(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'result' => ['points' => [], 'next_page_offset' => null],
            ]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $result = $client->getMemory('mem_1_nonexistent');

        $this->assertNull($result);
    }

    /**
     * Regression lock for issue where deleteMemory was sending the derived
     * UUIDv5 as the primary ID, which silently no-ops on legacy points stored
     * by the pre-v2.4.0 Rust microservice under integer IDs. The delete MUST
     * target the point by `_point_id` payload filter so both schemes resolve.
     */
    public function testDeleteMemoryUsesPointIdPayloadFilterNotPrimaryKey(): void
    {
        $capturedUrl = null;
        $capturedBody = null;

        $factory = function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody): MockResponse {
            $capturedUrl = $url;
            $capturedBody = $options['body'] ?? null;

            return new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]);
        };

        $mockClient = new MockHttpClient($factory);
        $client = new QdrantClientDirect(
            httpClient: $mockClient,
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $client->deleteMemory('mem_1_123');

        $this->assertIsString($capturedUrl);
        $this->assertStringContainsString('/collections/user_memories/points/delete', $capturedUrl);
        $this->assertStringContainsString('wait=true', $capturedUrl);

        $this->assertIsString($capturedBody);
        /** @var array{filter?: array{must?: array<array{key?: string, match?: array{value?: string}}>}, points?: mixed} $decoded */
        $decoded = json_decode($capturedBody, true);

        // Must delete by payload filter, not by primary `points` ID list.
        $this->assertArrayNotHasKey('points', $decoded, 'deleteMemory must not send primary-ID list; that breaks legacy int-keyed points');
        $this->assertArrayHasKey('filter', $decoded);
        $this->assertSame('_point_id', $decoded['filter']['must'][0]['key'] ?? null);
        $this->assertSame('mem_1_123', $decoded['filter']['must'][0]['match']['value'] ?? null);
    }

    public function testDeleteMemorySuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $client->deleteMemory('mem_1_123');
    }

    /**
     * Regression lock for the getMemory read path — must scroll by `_point_id`
     * payload filter (not lookup by primary UUID).
     */
    public function testGetMemoryUsesPointIdPayloadFilterNotPrimaryKey(): void
    {
        $capturedUrl = null;
        $capturedBody = null;

        $factory = function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedBody): MockResponse {
            $capturedUrl = $url;
            $capturedBody = $options['body'] ?? null;

            return new MockResponse(json_encode([
                'result' => ['points' => [], 'next_page_offset' => null],
            ]), ['http_code' => 200]);
        };

        $mockClient = new MockHttpClient($factory);
        $client = new QdrantClientDirect(
            httpClient: $mockClient,
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
        );

        $client->getMemory('mem_1_123');

        $this->assertIsString($capturedUrl);
        $this->assertStringContainsString('/collections/user_memories/points/scroll', $capturedUrl);

        $this->assertIsString($capturedBody);
        /** @var array{filter?: array{must?: array<array{key?: string, match?: array{value?: string}}>}, ids?: mixed} $decoded */
        $decoded = json_decode($capturedBody, true);

        $this->assertArrayNotHasKey('ids', $decoded, 'getMemory must not lookup by primary ID; that misses legacy int-keyed points');
        $this->assertArrayHasKey('filter', $decoded);
        $this->assertSame('_point_id', $decoded['filter']['must'][0]['key'] ?? null);
        $this->assertSame('mem_1_123', $decoded['filter']['must'][0]['match']['value'] ?? null);
    }

    public function testSearchMemoriesSuccess(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'result' => [
                    [
                        'id' => 'some-uuid',
                        'score' => 0.95,
                        'payload' => [
                            '_point_id' => 'mem_1_123',
                            'user_id' => 1,
                            'category' => 'test',
                            'key' => 'test_key',
                            'value' => 'test_value',
                            'active' => true,
                        ],
                    ],
                ],
            ]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $results = $client->searchMemories(
            queryVector: array_fill(0, 1024, 0.5),
            userId: 1,
            category: null,
            limit: 5,
            minScore: 0.7
        );

        $this->assertCount(1, $results);
        $this->assertEquals('mem_1_123', $results[0]['id']);
        $this->assertEquals(0.95, $results[0]['score']);
    }

    public function testHealthCheckSuccess(): void
    {
        $responses = [
            new MockResponse('', ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $healthy = $client->healthCheck();

        $this->assertTrue($healthy);
    }

    public function testHealthCheckFailure(): void
    {
        $responses = [
            new MockResponse('Service Unavailable', ['http_code' => 503]),
        ];

        $client = $this->createClient($responses);
        $healthy = $client->healthCheck();

        $this->assertFalse($healthy);
    }

    public function testHealthCheckNotConfigured(): void
    {
        $mockClient = new MockHttpClient([]);
        $client = new QdrantClientDirect(
            httpClient: $mockClient,
            qdrantUrl: '',
            logger: new NullLogger(),
        );

        $this->assertFalse($client->healthCheck());
        $this->assertFalse($client->isAvailable());
    }

    public function testUpsertDocumentSuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            // ensureDocumentsCollection -> GET /collections/user_documents
            new MockResponse(json_encode(['result' => ['status' => 'green']]), ['http_code' => 200]),
            // ensurePayloadIndexes -> PUT /collections/user_documents/index  x4
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            new MockResponse(json_encode(['result' => ['status' => 'acknowledged']]), ['http_code' => 200]),
            // upsertDocument -> pre-upsert POST /points/delete?wait=true
            new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]),
            // upsertDocument -> PUT /points?wait=true
            new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $client->upsertDocument(
            pointId: 'doc_1_42_0',
            vector: array_fill(0, 1024, 0.1),
            payload: [
                'user_id' => 1,
                'file_id' => 42,
                'group_key' => 'default',
                'text' => 'test chunk',
            ]
        );
    }

    public function testSearchDocumentsSuccess(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'result' => [
                    [
                        'id' => 'some-uuid',
                        'score' => 0.85,
                        'payload' => [
                            '_point_id' => 'doc_1_42_0',
                            'user_id' => 1,
                            'file_id' => 42,
                            'text' => 'matching chunk',
                            'group_key' => 'default',
                            'start_line' => 0,
                            'end_line' => 10,
                        ],
                    ],
                ],
            ]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $results = $client->searchDocuments(
            vector: array_fill(0, 1024, 0.1),
            userId: 1,
        );

        $this->assertCount(1, $results);
        $this->assertEquals(0.85, $results[0]['score']);
        $this->assertEquals('matching chunk', $results[0]['payload']['text']);
    }

    public function testScrollMemoriesSuccess(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'result' => [
                    'points' => [
                        [
                            'id' => 'uuid-1',
                            'payload' => [
                                '_point_id' => 'mem_1_1',
                                'user_id' => 1,
                                'category' => 'general',
                                'key' => 'name',
                                'value' => 'Test User',
                                'active' => true,
                            ],
                        ],
                        [
                            'id' => 'uuid-2',
                            'payload' => [
                                '_point_id' => 'mem_1_2',
                                'user_id' => 1,
                                'category' => 'preference',
                                'key' => 'language',
                                'value' => 'German',
                                'active' => true,
                            ],
                        ],
                    ],
                    'next_page_offset' => null,
                ],
            ]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $memories = $client->scrollMemories(userId: 1);

        $this->assertCount(2, $memories);
        $this->assertEquals('mem_1_1', $memories[0]['id']);
        $this->assertEquals('mem_1_2', $memories[1]['id']);
    }
}
