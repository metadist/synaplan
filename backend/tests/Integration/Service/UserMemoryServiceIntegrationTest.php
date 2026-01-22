<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\User;
use App\Service\UserMemoryService;
use App\Service\VectorSearch\QdrantClientInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration Tests für UserMemoryService.
 * Testet mit echtem Service Container und Dependency Injection.
 */
final class UserMemoryServiceIntegrationTest extends KernelTestCase
{
    private UserMemoryService $service;
    private QdrantClientInterface $qdrantClient;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->service = $container->get(UserMemoryService::class);
        $this->qdrantClient = $container->get(QdrantClientInterface::class);
    }

    public function testServiceCanBeInstantiatedFromContainer(): void
    {
        $this->assertInstanceOf(UserMemoryService::class, $this->service);
    }

    public function testIsAvailableReflectsQdrantClientState(): void
    {
        $isAvailable = $this->service->isAvailable();

        // Should match Qdrant client availability
        $this->assertEquals($this->qdrantClient->isAvailable(), $isAvailable);
    }

    public function testGetQdrantClientReturnsCorrectInstance(): void
    {
        $client = $this->service->getQdrantClient();

        $this->assertInstanceOf(QdrantClientInterface::class, $client);
        $this->assertSame($this->qdrantClient, $client);
    }

    public function testDeleteMemoryDoesNotThrowWhenServiceUnavailable(): void
    {
        // Even if Qdrant is unavailable, delete should not throw
        // (it logs and continues)

        // Create a real user entity using proper setters
        $user = new User();
        // User entity might use different setter name
        // Just test with reflection to avoid dependency on exact setter

        $reflection = new \ReflectionClass($user);

        // Set email via property
        try {
            $emailProperty = $reflection->getProperty('email');
            $emailProperty->setAccessible(true);
            $emailProperty->setValue($user, 'test@example.com');
        } catch (\ReflectionException $e) {
            // If property doesn't exist, skip this part
        }

        // Set ID via reflection (entity not persisted)
        try {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($user, 123);
        } catch (\ReflectionException $e) {
            $this->markTestSkipped('Could not set user ID via reflection');
        }

        $this->expectNotToPerformAssertions();

        try {
            $this->service->deleteMemory(1768900000, $user);
        } catch (\Exception $e) {
            $this->fail('deleteMemory should not throw exception: '.$e->getMessage());
        }
    }
}
