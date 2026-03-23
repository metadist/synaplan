<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\VectorSearch;

use App\Service\VectorSearch\QdrantClientMock;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit Tests for QdrantClientMock.
 * Verifies the mock returns safe defaults for all interface methods.
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
        $this->assertFalse($this->client->isAvailable());
    }

    public function testHealthCheckReturnsFalse(): void
    {
        $this->assertFalse($this->client->healthCheck());
    }

    public function testGetHealthDetailsReturnsMockData(): void
    {
        $details = $this->client->getHealthDetails();

        $this->assertArrayHasKey('status', $details);
        $this->assertEquals('mock', $details['status']);
        $this->assertArrayHasKey('qdrant', $details);
    }

    public function testGetServiceInfoReturnsMockData(): void
    {
        $info = $this->client->getServiceInfo();

        $this->assertArrayHasKey('service', $info);
        $this->assertArrayHasKey('version', $info);
        $this->assertStringContainsString('mock', $info['version']);
    }

    public function testGetCollectionInfoReturnsMockData(): void
    {
        $info = $this->client->getCollectionInfo();

        $this->assertArrayHasKey('status', $info);
        $this->assertArrayHasKey('points_count', $info);
        $this->assertEquals(0, $info['points_count']);
    }

    // --- Memory Operations ---

    public function testUpsertMemoryDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->upsertMemory('test_id', array_fill(0, 1024, 0.5), ['test' => 'payload']);
    }

    public function testGetMemoryReturnsNull(): void
    {
        $this->assertNull($this->client->getMemory('test_id'));
    }

    public function testSearchMemoriesReturnsEmptyArray(): void
    {
        $this->assertEmpty($this->client->searchMemories(array_fill(0, 1024, 0.5), 123));
    }

    public function testScrollMemoriesReturnsEmptyArray(): void
    {
        $this->assertEmpty($this->client->scrollMemories(123));
    }

    public function testDeleteMemoryDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->deleteMemory('test_id');
    }

    public function testDeleteAllMemoriesForUserReturnsZero(): void
    {
        $this->assertEquals(0, $this->client->deleteAllMemoriesForUser(1));
    }

    // --- Document Operations ---

    public function testUpsertDocumentDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->upsertDocument('doc_1', array_fill(0, 1024, 0.1), ['user_id' => 1]);
    }

    public function testBatchUpsertDocumentsReturnsZeros(): void
    {
        $result = $this->client->batchUpsertDocuments([]);
        $this->assertEquals(0, $result['success_count']);
    }

    public function testSearchDocumentsReturnsEmptyArray(): void
    {
        $this->assertEmpty($this->client->searchDocuments(array_fill(0, 1024, 0.1), 1));
    }

    public function testGetDocumentReturnsNull(): void
    {
        $this->assertNull($this->client->getDocument('doc_1'));
    }

    public function testDeleteDocumentDoesNotThrow(): void
    {
        $this->expectNotToPerformAssertions();
        $this->client->deleteDocument('doc_1');
    }

    public function testDeleteDocumentsByFileReturnsZero(): void
    {
        $this->assertEquals(0, $this->client->deleteDocumentsByFile(1, 42));
    }

    public function testGetDocumentStatsReturnsEmptyDefaults(): void
    {
        $stats = $this->client->getDocumentStats(1);
        $this->assertEquals(0, $stats['total_chunks']);
        $this->assertEmpty($stats['chunks_by_file']);
    }

    public function testGetDocumentFileInfoReturnsZero(): void
    {
        $info = $this->client->getDocumentFileInfo(1, 42);
        $this->assertEquals(0, $info['chunks']);
        $this->assertNull($info['groupKey']);
    }

    public function testGetFilesWithChunksReturnsEmptyArray(): void
    {
        $this->assertEmpty($this->client->getFilesWithChunks(1));
    }
}
