<?php

declare(strict_types=1);

namespace App\Tests\Integration\Service;

use App\Service\MemoryExtractionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Integration Tests für MemoryExtractionService.
 * Testet mit echtem Service Container.
 */
final class MemoryExtractionServiceIntegrationTest extends KernelTestCase
{
    private MemoryExtractionService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(MemoryExtractionService::class);
    }

    public function testServiceCanBeInstantiatedFromContainer(): void
    {
        $this->assertInstanceOf(MemoryExtractionService::class, $this->service);
    }

    public function testServiceHasRequiredDependencies(): void
    {
        $this->expectNotToPerformAssertions();
    }
}
