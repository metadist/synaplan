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
    private function createClient(array $responses): QdrantClientDirect
    {
        $mockClient = new MockHttpClient($responses);

        return new QdrantClientDirect(
            httpClient: $mockClient,
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
            apiKey: 'test-api-key',
        );
    }

    public function testUpsertMemorySuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            // ensureMemoriesCollection -> GET /collections/user_memories
            new MockResponse(json_encode(['result' => ['status' => 'green']]), ['http_code' => 200]),
            // upsertPoints -> PUT /collections/user_memories/points
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
            // POST /collections/user_memories/points (get by ID)
            new MockResponse(json_encode([
                'result' => [
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
            new MockResponse(json_encode(['result' => []]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $result = $client->getMemory('mem_1_nonexistent');

        $this->assertNull($result);
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

    public function testDeleteMemorySuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            new MockResponse(json_encode(['result' => ['status' => 'completed']]), ['http_code' => 200]),
        ];

        $client = $this->createClient($responses);
        $client->deleteMemory('mem_1_123');
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

    public function testApiKeyIsIncludedInRequests(): void
    {
        $requestHeaders = null;

        $mockClient = new MockHttpClient(function ($method, $url, $options) use (&$requestHeaders) {
            $requestHeaders = $options['headers'] ?? [];

            return new MockResponse(json_encode(['result' => ['status' => 'green']]), ['http_code' => 200]);
        });

        $client = new QdrantClientDirect(
            httpClient: $mockClient,
            qdrantUrl: 'http://localhost:6333',
            logger: new NullLogger(),
            apiKey: 'secret-test-key',
        );

        // Trigger a health check to exercise the request path
        $client->healthCheck();

        $this->assertNotNull($requestHeaders);

        $hasApiKey = false;
        foreach ($requestHeaders as $key => $value) {
            if (is_string($key) && 'api-key' === strtolower($key)) {
                $hasApiKey = true;
                $this->assertEquals('secret-test-key', is_array($value) ? $value[0] : $value);
            } elseif (is_string($value) && str_starts_with(strtolower($value), 'api-key:')) {
                $hasApiKey = true;
                $this->assertStringContainsString('secret-test-key', $value);
            }
        }

        $this->assertTrue($hasApiKey, 'API Key header not found in request');
    }

    public function testUpsertDocumentSuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            // ensureDocumentsCollection -> GET /collections/user_documents
            new MockResponse(json_encode(['result' => ['status' => 'green']]), ['http_code' => 200]),
            // upsertPoints -> PUT /collections/user_documents/points
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
