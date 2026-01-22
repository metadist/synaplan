<?php

declare(strict_types=1);

namespace App\Tests\Service\VectorSearch;

use App\Service\VectorSearch\QdrantClientHttp;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Tests für QdrantClientHttp.
 *
 * Diese Tests nutzen Mock HTTP Responses und brauchen keinen laufenden Qdrant Service.
 * Perfekt für CI/CD Pipelines!
 */
final class QdrantClientHttpTest extends TestCase
{
    protected function tearDown(): void
    {
        // Clear static cache between tests to ensure test isolation
        $reflection = new \ReflectionClass(QdrantClientHttp::class);
        $cacheProperty = $reflection->getProperty('healthCheckCache');
        $cacheProperty->setAccessible(true);
        $cacheProperty->setValue(null, null);

        $timeProperty = $reflection->getProperty('healthCheckCacheTime');
        $timeProperty->setAccessible(true);
        $timeProperty->setValue(null, null);

        parent::tearDown();
    }

    private function createClient(array $responses): QdrantClientHttp
    {
        $mockClient = new MockHttpClient($responses);

        return new QdrantClientHttp(
            httpClient: $mockClient,
            baseUrl: 'http://localhost:8080',
            logger: new NullLogger(),
            apiKey: 'test-api-key',
        );
    }

    public function testUpsertMemorySuccess(): void
    {
        $this->expectNotToPerformAssertions();

        $responses = [
            new MockResponse(json_encode([
                'success' => true,
                'point_id' => 'mem_1_123',
                'message' => 'Memory upserted successfully',
            ])),
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

    public function testUpsertMemoryFailure(): void
    {
        $responses = [
            new MockResponse('{"error":"Database operation failed"}', [
                'http_code' => 500,
            ]),
        ];

        $client = $this->createClient($responses);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to upsert memory');

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
            new MockResponse(json_encode([
                'id' => 'mem_1_123',
                'payload' => [
                    'user_id' => 1,
                    'category' => 'test',
                    'key' => 'test_key',
                    'value' => 'test_value',
                    'source' => 'test',
                    'created' => 1234567890,
                    'updated' => 1234567890,
                    'active' => true,
                ],
            ])),
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
            new MockResponse('{"error":"Memory not found"}', [
                'http_code' => 404,
            ]),
        ];

        $client = $this->createClient($responses);
        $result = $client->getMemory('mem_1_nonexistent');

        $this->assertNull($result);
    }

    public function testSearchMemoriesSuccess(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'results' => [
                    [
                        'id' => 'mem_1_123',
                        'score' => 0.95,
                        'payload' => [
                            'user_id' => 1,
                            'category' => 'test',
                            'key' => 'test_key',
                            'value' => 'test_value',
                            'source' => 'test',
                            'created' => 1234567890,
                            'updated' => 1234567890,
                            'active' => true,
                        ],
                    ],
                ],
                'count' => 1,
            ])),
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
            new MockResponse(json_encode([
                'success' => true,
                'message' => 'Memory deleted successfully',
            ])),
        ];

        $client = $this->createClient($responses);
        $client->deleteMemory('mem_1_123');
    }

    public function testHealthCheckSuccess(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'status' => 'healthy',
                'qdrant' => 'connected',
            ])),
        ];

        $client = $this->createClient($responses);
        $healthy = $client->healthCheck();

        $this->assertTrue($healthy);
    }

    public function testHealthCheckFailure(): void
    {
        $responses = [
            new MockResponse('Service Unavailable', [
                'http_code' => 503,
            ]),
        ];

        $client = $this->createClient($responses);
        $healthy = $client->healthCheck();

        $this->assertFalse($healthy);
    }

    public function testGetCollectionInfoSuccess(): void
    {
        $responses = [
            new MockResponse(json_encode([
                'status' => '1',
                'points_count' => 100,
                'vectors_count' => 100,
                'indexed_vectors_count' => 100,
            ])),
        ];

        $client = $this->createClient($responses);
        $info = $client->getCollectionInfo();

        $this->assertEquals('1', $info['status']);
        $this->assertEquals(100, $info['points_count']);
    }

    public function testApiKeyIsIncludedInRequests(): void
    {
        $requestHeaders = null;

        $mockClient = new MockHttpClient(function ($method, $url, $options) use (&$requestHeaders) {
            $requestHeaders = $options['headers'] ?? [];

            return new MockResponse(json_encode(['success' => true]));
        });

        $client = new QdrantClientHttp(
            httpClient: $mockClient,
            baseUrl: 'http://localhost:8080',
            logger: new NullLogger(),
            apiKey: 'secret-test-key',
        );

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

        $this->assertNotNull($requestHeaders);

        // HttpClient headers format: ['X-API-Key' => ['secret-test-key']] or ['x-api-key: secret-test-key']
        $hasApiKey = false;
        foreach ($requestHeaders as $key => $value) {
            if (is_string($key) && 'x-api-key' === strtolower($key)) {
                $hasApiKey = true;
                $this->assertEquals('secret-test-key', is_array($value) ? $value[0] : $value);
            } elseif (is_string($value) && str_starts_with(strtolower($value), 'x-api-key:')) {
                $hasApiKey = true;
                $this->assertStringContainsString('secret-test-key', $value);
            }
        }

        $this->assertTrue($hasApiKey, 'API Key header not found in request');
    }
}
