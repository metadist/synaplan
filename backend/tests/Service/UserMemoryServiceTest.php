<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\AI\Service\AiFacade;
use App\Entity\User;
use App\Service\ModelConfigService;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests für UserMemoryService (Qdrant-basiert).
 */
final class UserMemoryServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private QdrantClientInterface $qdrantClient;
    private AiFacade $aiFacade;
    private ModelConfigService $modelConfigService;
    private LoggerInterface $logger;
    private UserMemoryService $service;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->aiFacade = $this->createMock(AiFacade::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new UserMemoryService(
            $this->em,
            $this->qdrantClient,
            $this->aiFacade,
            $this->modelConfigService,
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
            ->method('deleteMemory')
            ->with("mem_123_{$memoryId}");

        $this->service->deleteMemory($memoryId, $user);
    }

    public function testServiceIsAvailableWhenQdrantConfigured(): void
    {
        $this->qdrantClient
            ->method('isAvailable')
            ->willReturn(true);

        $result = $this->service->isAvailable();

        $this->assertTrue($result);
    }
}
