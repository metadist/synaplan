<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Service\MemoryExtractionService;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Smoke Tests für MemoryExtractionService.
 * Testet nur grundlegende Funktionalität ohne komplexe Mocks.
 */
final class MemoryExtractionServiceTest extends KernelTestCase
{
    private MemoryExtractionService $service;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        $this->service = $container->get(MemoryExtractionService::class);
    }

    public function testServiceCanBeInstantiated(): void
    {
        $this->assertInstanceOf(MemoryExtractionService::class, $this->service);
    }

    public function testServiceIsAvailableViaContainer(): void
    {
        $service = static::getContainer()->get(MemoryExtractionService::class);
        $this->assertInstanceOf(MemoryExtractionService::class, $service);
    }
}
