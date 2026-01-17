<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\User;
use App\Entity\UserMemory;
use App\Repository\UserMemoryRepository;
use App\Service\ModelConfigService;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class UserMemoryServiceTest extends TestCase
{
    private EntityManagerInterface $em;
    private UserMemoryRepository $memoryRepository;
    private ModelConfigService $modelConfigService;
    private QdrantClientInterface $qdrantClient;
    private LoggerInterface $logger;
    private UserMemoryService $service;
    private User $testUser;

    protected function setUp(): void
    {
        $this->em = $this->createMock(EntityManagerInterface::class);
        $this->memoryRepository = $this->createMock(UserMemoryRepository::class);
        $this->modelConfigService = $this->createMock(ModelConfigService::class);
        $this->qdrantClient = $this->createMock(QdrantClientInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->service = new UserMemoryService(
            $this->em,
            $this->memoryRepository,
            $this->modelConfigService,
            $this->qdrantClient,
            $this->logger
        );

        $this->testUser = new User();
        $this->testUser->setEmail('test@example.com');
    }

    public function testCreateMemory(): void
    {
        // Arrange
        $this->memoryRepository
            ->expects($this->once())
            ->method('countByUser')
            ->willReturn(0);

        $this->em
            ->expects($this->once())
            ->method('persist');

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->qdrantClient
            ->expects($this->once())
            ->method('addMemory');

        // Act
        $memory = $this->service->createMemory(
            $this->testUser,
            'preferences',
            'tech_stack',
            'TypeScript with Vue 3',
            'user_created'
        );

        // Assert
        $this->assertInstanceOf(UserMemory::class, $memory);
        $this->assertSame('preferences', $memory->getCategory());
        $this->assertSame('tech_stack', $memory->getKey());
        $this->assertSame('TypeScript with Vue 3', $memory->getValue());
        $this->assertSame('user_created', $memory->getSource());
    }

    public function testCreateMemoryThrowsExceptionForShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory key must be at least 3 characters');

        $this->service->createMemory(
            $this->testUser,
            'preferences',
            'ab', // Too short
            'TypeScript',
            'user_created'
        );
    }

    public function testCreateMemoryThrowsExceptionForShortValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory value must be at least 5 characters');

        $this->service->createMemory(
            $this->testUser,
            'preferences',
            'tech',
            'Vue', // Too short
            'user_created'
        );
    }

    public function testCreateMemoryThrowsExceptionWhenLimitReached(): void
    {
        // Arrange
        $this->memoryRepository
            ->expects($this->once())
            ->method('countByUser')
            ->willReturn(1000); // Max limit

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory limit reached');

        // Act
        $this->service->createMemory(
            $this->testUser,
            'preferences',
            'tech_stack',
            'TypeScript with Vue 3',
            'user_created'
        );
    }

    public function testUpdateMemory(): void
    {
        // Arrange
        $memory = new UserMemory();
        $memory->setCategory('preferences');
        $memory->setKey('tech_stack');
        $memory->setValue('React');
        $memory->setSource('user_created');

        $this->memoryRepository
            ->expects($this->once())
            ->method('findByIdAndUser')
            ->willReturn($memory);

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->qdrantClient
            ->expects($this->once())
            ->method('updateMemory');

        // Act
        $updatedMemory = $this->service->updateMemory(
            1,
            $this->testUser,
            'TypeScript with Vue 3'
        );

        // Assert
        $this->assertSame('TypeScript with Vue 3', $updatedMemory->getValue());
        $this->assertSame('user_edited', $updatedMemory->getSource());
    }

    public function testUpdateMemoryThrowsExceptionWhenNotFound(): void
    {
        // Arrange
        $this->memoryRepository
            ->expects($this->once())
            ->method('findByIdAndUser')
            ->willReturn(null);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory not found or access denied');

        // Act
        $this->service->updateMemory(
            999,
            $this->testUser,
            'New value'
        );
    }

    public function testDeleteMemory(): void
    {
        // Arrange
        $memory = new UserMemory();
        $memory->setCategory('preferences');
        $memory->setKey('tech_stack');
        $memory->setValue('TypeScript');

        $this->memoryRepository
            ->expects($this->once())
            ->method('findByIdAndUser')
            ->willReturn($memory);

        $this->em
            ->expects($this->once())
            ->method('flush');

        $this->qdrantClient
            ->expects($this->once())
            ->method('deleteMemory');

        // Act
        $this->service->deleteMemory(1, $this->testUser);

        // Assert
        $this->assertTrue($memory->isDeleted());
    }

    public function testGetMemoriesByUser(): void
    {
        // Arrange
        $memories = [
            (new UserMemory())->setCategory('preferences')->setKey('tech_stack'),
            (new UserMemory())->setCategory('work')->setKey('role'),
        ];

        $this->memoryRepository
            ->expects($this->once())
            ->method('findActiveByUser')
            ->with($this->testUser->getId(), null)
            ->willReturn($memories);

        // Act
        $result = $this->service->getMemoriesByUser($this->testUser);

        // Assert
        $this->assertCount(2, $result);
        $this->assertSame($memories, $result);
    }

    public function testGetMemoriesByUserWithCategoryFilter(): void
    {
        // Arrange
        $memories = [
            (new UserMemory())->setCategory('preferences')->setKey('tech_stack'),
        ];

        $this->memoryRepository
            ->expects($this->once())
            ->method('findActiveByUser')
            ->with($this->testUser->getId(), 'preferences')
            ->willReturn($memories);

        // Act
        $result = $this->service->getMemoriesByUser($this->testUser, 'preferences');

        // Assert
        $this->assertCount(1, $result);
        $this->assertSame('preferences', $result[0]->getCategory());
    }

    public function testGetCategoriesWithCounts(): void
    {
        // Arrange
        $expectedCategories = [
            ['category' => 'preferences', 'count' => 5],
            ['category' => 'work', 'count' => 3],
        ];

        $this->memoryRepository
            ->expects($this->once())
            ->method('getCategoriesWithCounts')
            ->with($this->testUser->getId())
            ->willReturn($expectedCategories);

        // Act
        $result = $this->service->getCategoriesWithCounts($this->testUser);

        // Assert
        $this->assertSame($expectedCategories, $result);
    }
}
