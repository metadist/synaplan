<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Multitask\Execution\Runner;

use App\Repository\ComputeRunRepository;
use App\Repository\FileRepository;
use App\Repository\UserRepository;
use App\Service\Compute\ComputeArtefactStore;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Multitask\Execution\Runner\CodeRunRunner;
use App\Service\Multitask\Plan\Capability;
use App\Service\RateLimitService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CodeRunRunnerAvailabilityTest extends TestCase
{
    public function testDescriptorIsHiddenWhenComputeIsOff(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(false);
        $runner = $this->runner($config);

        $this->assertSame([Capability::CodeRun], $runner->supportedCapabilities());
        $this->assertFalse($runner->describe()[0]->isAvailable());
    }

    public function testDescriptorIsVisibleWhenComputeIsOn(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isEnabled')->willReturn(true);

        $this->assertTrue($this->runner($config)->describe()[0]->isAvailable());
    }

    private function runner(ComputeConfig $config): CodeRunRunner
    {
        return new CodeRunRunner(
            $config,
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeArtefactStore::class),
            $this->createStub(ComputeRunRepository::class),
            $this->createStub(FileRepository::class),
            $this->createStub(UserRepository::class),
            $this->createStub(RateLimitService::class),
            new NullLogger(),
            '/tmp',
        );
    }
}
