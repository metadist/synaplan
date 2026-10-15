<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Memory;

use App\Service\Memory\MemoryServiceStatus;
use App\Service\Memory\UserMemoryService;
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
        $memory = $this->createMock(UserMemoryService::class);
        $memory->expects(self::once())->method('isReachableNow')->willReturn(false);

        self::assertSame(['available' => false, 'configured' => true], (new MemoryServiceStatus($memory, 'http://qdrant:6333'))->probe());
    }
}
