<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\SelfAware;

use App\Service\Compute\ComputeConfig;
use App\Service\SelfAware\CapabilityState;
use PHPUnit\Framework\TestCase;

/**
 * CS5: code_execution leaves KNOWN_ABSENT only when compute is on.
 *
 * Construction is delegated to {@see PlatformCapabilityInventoryTest} so the
 * collaborator graph stays in one place.
 */
final class PlatformCapabilityInventoryComputeTest extends TestCase
{
    public function testCodeExecutionStaysAbsentWhenComputeIsOff(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(false);

        $host = new PlatformCapabilityInventoryTest('testCodeExecutionStaysAbsentWhenComputeIsOff');
        $fact = $host->inventoryForCompute($config)->build(2)->fact('code_execution');

        $this->assertNotNull($fact);
        $this->assertSame(CapabilityState::Absent, $fact->state);
        $this->assertSame('Running arbitrary code', $fact->label);
    }

    public function testCodeExecutionBecomesAvailableWhenComputeIsOn(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $host = new PlatformCapabilityInventoryTest('testCodeExecutionBecomesAvailableWhenComputeIsOn');
        $fact = $host->inventoryForCompute($config)->build(2)->fact('code_execution');

        $this->assertNotNull($fact);
        $this->assertSame(CapabilityState::Available, $fact->state);
        $this->assertSame('File work', $fact->label);
    }
}
