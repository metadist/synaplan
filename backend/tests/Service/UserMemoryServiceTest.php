<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\Exception\MemoryServiceUnavailableException;
use App\Service\ModelConfigService;
use App\Service\RateLimitService;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests für UserMemoryService (Qdrant-basiert).
 */
final class UserMemoryServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    /** @var QdrantClientInterface&MockObject */
    private QdrantClientInterface $qdrantClient;
    private AiFacade $aiFacade;
    private ModelConfigService $modelConfigService;
    private RateLimitService $rateLimitService;
    private LoggerInterface $logger;
    private UserMemoryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->rateLimitService = $this->createMock(RateLimitService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new UserMemoryService(
            $this->em,
            $this->qdrantClient,
            $this->aiFacade,
            $this->modelConfigService,
            $this->rateLimitService,
            $this->logger
        );
    }

    public function testIsAvailableDelegatesToQdrantClient(): void
    {
        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $result = $this->service->isAvailable();

        $this->assertTrue($result);
    }

    public function testIsAvailableReturnsFalseWhenQdrantUnavailable(): void
    {
        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $result = $this->service->isAvailable();

        $this->assertFalse($result);
    }

    public function testGetQdrantClientReturnsClient(): void
    {
        $client = $this->service->getQdrantClient();

        $this->assertSame($this->qdrantClient, $client);
    }

    public function testDeleteMemoryCallsQdrantClient(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->qdrantClient
            ->expects($this->once())
            ->method('deleteMemory')
            ->with("mem_123_{$memoryId}");

        $this->service->deleteMemory($memoryId, $user);
    }

    public function testDeleteMemoryThrowsWhenQdrantUnavailable(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->qdrantClient
            ->expects($this->never())
            ->method('deleteMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteMemory($memoryId, $user);
    }

    public function testCreateMemoryThrowsWhenQdrantUnavailable(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->qdrantClient
            ->expects($this->never())
            ->method('upsertMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->createMemory($user, 'personal', 'favourite_colour', 'green');
    }

    public function testUpdateMemoryThrowsWhenQdrantUnavailable(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(false);

        $this->qdrantClient
            ->expects($this->never())
            ->method('getMemory');
        $this->qdrantClient
            ->expects($this->never())
            ->method('upsertMemory');

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->updateMemory(1768900000, $user, 'new value');
    }

    /**
     * Covers the race window where isAvailable() returns true (health cache
     * hit) but the subsequent Qdrant call fails. The service must translate
     * the underlying \RuntimeException into MemoryServiceUnavailableException
     * so the controller returns 503 instead of leaking the raw error as a
     * misleading 400 or 500.
     */
    public function testDeleteMemoryMapsRuntimeExceptionFromQdrantTo503(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->qdrantClient
            ->expects($this->once())
            ->method('deleteMemory')
            ->with("mem_123_{$memoryId}")
            ->willThrowException(new \RuntimeException('Qdrant request failed: HTTP 500'));

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteMemory($memoryId, $user);
    }

    /**
     * Same race as above, but for updateMemory() where the failure happens
     * during the preflight getMemory() lookup.
     */
    public function testUpdateMemoryMapsRuntimeExceptionFromQdrantTo503(): void
    {
        $memoryId = 1768900000;
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(123);

        $this->qdrantClient
            ->expects($this->once())
            ->method('isAvailable')
            ->willReturn(true);

        $this->qdrantClient
            ->expects($this->once())
            ->method('getMemory')
            ->with("mem_123_{$memoryId}")
            ->willThrowException(new \RuntimeException('Qdrant request failed: connection refused'));

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->updateMemory($memoryId, $user, 'new value');
    }

    public function testServiceIsAvailableWhenQdrantConfigured(): void
    {
        $this->qdrantClient
            ->method('isAvailable')
            ->willReturn(true);

        $result = $this->service->isAvailable();

        $this->assertTrue($result);
    }

    public function testResolveMemoryTagsWithNoTags(): void
    {
        $user = $this->createMock(User::class);

        $result = $this->service->resolveMemoryTags('Hello world', $user);

        $this->assertSame('Hello world', $result);
    }

    public function testResolveMemoryTagsSingleTag(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('getMemory')
            ->with('mem_1_12345')
            ->willReturn(['key' => 'name', 'value' => 'Cristian', 'category' => 'personal']);

        $result = $this->service->resolveMemoryTags('Hallo [Memory:12345]', $user);

        $this->assertSame('Hallo Cristian', $result);
    }

    public function testResolveMemoryTagsMultipleDifferentTags(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('getMemory')
            ->willReturnCallback(fn (string $pointId): ?array => match ($pointId) {
                'mem_1_111' => ['key' => 'name', 'value' => 'Cristian', 'category' => 'personal'],
                'mem_1_222' => ['key' => 'city', 'value' => 'Berlin', 'category' => 'personal'],
                default => null,
            });

        $result = $this->service->resolveMemoryTags(
            'Hallo [Memory:111], du wohnst in [Memory:222]!',
            $user
        );

        $this->assertSame('Hallo Cristian, du wohnst in Berlin!', $result);
    }

    public function testResolveMemoryTagsRepeatedIdOnlyLookedUpOnce(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->expects($this->once())
            ->method('getMemory')
            ->with('mem_1_111')
            ->willReturn(['key' => 'name', 'value' => 'Cristian', 'category' => 'personal']);

        $result = $this->service->resolveMemoryTags(
            '[Memory:111] ist [Memory:111]',
            $user
        );

        $this->assertSame('Cristian ist Cristian', $result);
    }

    public function testResolveMemoryTagsUnknownIdRemoved(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('getMemory')->willReturn(null);

        $result = $this->service->resolveMemoryTags('Hallo [Memory:99999]!', $user);

        $this->assertSame('Hallo !', $result);
    }

    public function testResolveMemoryTagsHandlesTrailingDots(): void
    {
        $user = $this->createMock(User::class);
        $user->method('getId')->willReturn(1);

        $this->qdrantClient->method('isAvailable')->willReturn(true);
        $this->qdrantClient->method('getMemory')
            ->with('mem_1_12345')
            ->willReturn(['key' => 'name', 'value' => 'Cristian', 'category' => 'personal']);

        $result = $this->service->resolveMemoryTags('Hallo [Memory:12345...]', $user);

        $this->assertSame('Hallo Cristian', $result);
    }
}
