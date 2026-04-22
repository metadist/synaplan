<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Entity\User;
use App\Service\Exception\MemoryServiceUnavailableException;
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

    public function testDeleteMemoryThrowsWhenServiceUnavailable(): void
    {
        // When Qdrant is unavailable, user-initiated deletes must fail loudly
        // so the controller surfaces a 503 instead of silently confirming a
        // delete that never reached storage.

        if ($this->service->isAvailable()) {
            $this->markTestSkipped('Qdrant is reachable in this environment; skipping unavailable-path test.');
        }

        $user = new User();
        $reflection = new \ReflectionClass($user);

        try {
            $emailProperty = $reflection->getProperty('email');
            $emailProperty->setAccessible(true);
            $emailProperty->setValue($user, 'test@example.com');
        } catch (\ReflectionException) {
            // Property layout differs — acceptable for this test.
        }

        try {
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($user, 123);
        } catch (\ReflectionException $e) {
            $this->markTestSkipped('Could not set user ID via reflection');
        }

        $this->expectException(MemoryServiceUnavailableException::class);

        $this->service->deleteMemory(1768900000, $user);
    }
}
