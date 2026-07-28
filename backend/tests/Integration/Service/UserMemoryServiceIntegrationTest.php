<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

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

    public function testSqlReadsRemainAvailableWhenQdrantIsUnavailable(): void
    {
        if ($this->service->isAvailable()) {
            $this->markTestSkipped('Qdrant is reachable in this environment; skipping unavailable-path test.');
        }

        self::assertSame([], $this->service->getUserMemories(PHP_INT_MAX));
    }
}
