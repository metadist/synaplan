<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Memory;

use App\Service\Memory\MemoryServiceStatus;
use App\Service\Memory\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use PHPUnit\Framework\TestCase;

final class MemoryServiceStatusTest extends TestCase
{
    public function testAnUnconfiguredStoreIsNotProbed(): void
    {
        $memory = $this->createMock(UserMemoryService::class);
        $memory->expects(self::never())->method('isReachableNow');

        self::assertSame(['available' => false, 'configured' => false], (new MemoryServiceStatus($memory, '  '))->probe());
    }

    public function testAConfiguredStoreReportsWhetherItAnswers(): void
    {
        $client = $this->createStub(QdrantClientInterface::class);
        $client->method('unavailableReason')->willReturn(null);
        $memory = $this->createMock(UserMemoryService::class);
        $memory->expects(self::once())->method('isReachableNow')->willReturn(false);
        $memory->method('getQdrantClient')->willReturn($client);

        self::assertSame(['available' => false, 'configured' => true], (new MemoryServiceStatus($memory, 'http://qdrant:6333'))->probe());
    }

    public function testARejectedApiKeyIsAClearError(): void
    {
        $client = $this->createStub(QdrantClientInterface::class);
        $client->method('unavailableReason')->willReturn('Qdrant rejected the API key. Check that QDRANT_API_KEY matches the key configured on Qdrant.');
        $memory = $this->createStub(UserMemoryService::class);
        $memory->method('isReachableNow')->willReturn(false);
        $memory->method('getQdrantClient')->willReturn($client);

        $probe = (new MemoryServiceStatus($memory, 'http://qdrant:6333'))->probe();

        self::assertFalse($probe['available']);
        self::assertTrue($probe['configured']);
        self::assertSame('Qdrant rejected the API key. Check that QDRANT_API_KEY matches the key configured on Qdrant.', $probe['error']);
    }
}
