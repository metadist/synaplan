<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\VectorSearch;

use App\Service\VectorSearch\QdrantClientMock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit Tests für QdrantClientMock.
 * Der Mock ist eine echte Service-Implementierung (Fallback wenn Qdrant nicht verfügbar).
 */
final class QdrantClientMockTest extends TestCase
{
    private QdrantClientMock $client;

    protected function setUp(): void
    {
        $this->client = new QdrantClientMock(new NullLogger());
    }

    public function testIsAvailableReturnsFalse(): void
    {
        // Mock is never available (it's a placeholder)
        $this->assertFalse($this->client->isAvailable());
    }

    public function testHealthCheckReturnsFalse(): void
    {
        $this->assertFalse($this->client->healthCheck());
    }

    public function testGetHealthDetailsReturnsMockData(): void
    {
        $details = $this->client->getHealthDetails();

        $this->assertIsArray($details);
        $this->assertArrayHasKey('status', $details);
        $this->assertEquals('mock', $details['status']);
        $this->assertArrayHasKey('metrics', $details);
        $this->assertArrayHasKey('qdrant', $details);
    }

    public function testGetServiceInfoReturnsMockData(): void
    {
        $info = $this->client->getServiceInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('service', $info);
        $this->assertArrayHasKey('version', $info);
        $this->assertStringContainsString('mock', $info['version']);
    }

    public function testGetCollectionInfoReturnsMockData(): void
    {
        $info = $this->client->getCollectionInfo();

        $this->assertIsArray($info);
        $this->assertArrayHasKey('status', $info);
        $this->assertArrayHasKey('points_count', $info);
        $this->assertEquals(0, $info['points_count']);
    }

    public function testUpsertMemoryDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->client->upsertMemory(
            'test_point_id',
            array_fill(0, 1024, 0.5),
            ['test' => 'payload']
        );
    }

    public function testGetMemoryReturnsNull(): void
    {
        $result = $this->client->getMemory('test_point_id');

        $this->assertNull($result);
    }

    public function testSearchMemoriesReturnsEmptyArray(): void
    {
        $result = $this->client->searchMemories(
            array_fill(0, 1024, 0.5),
            123
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testScrollMemoriesReturnsEmptyArray(): void
    {
        $result = $this->client->scrollMemories(123);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function testDeleteMemoryDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();

        $this->client->deleteMemory('test_point_id');
    }
}
