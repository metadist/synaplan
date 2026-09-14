<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Repository\ConfigRepository;
use App\Service\Compute\ComputeConfig;
use PHPUnit\Framework\TestCase;

final class ComputeConfigTest extends TestCase
{
    public function testDisabledWhenUrlOrTokenMissing(): void
    {
        $this->assertFalse($this->config('', 'token')->hasSidecar());
        $this->assertFalse($this->config('http://compute:8080', '')->hasSidecar());
        $this->assertFalse($this->config('disabled', 'token')->hasSidecar());
        $this->assertTrue($this->config('http://compute:8080', 'token')->hasSidecar());
        $this->assertFalse($this->config('http://compute:8080', 'token')->isEnabled());
    }

    public function testClampLimitsNeverExceedDefaults(): void
    {
        $clamped = $this->config('http://compute:8080', 'token')->clampLimits([
            'timeoutSec' => 999,
            'memoryMb' => 9999,
            'cpu' => 8.0,
            'pids' => 9999,
            'outputMb' => 999,
        ]);

        $this->assertSame(60, $clamped['timeoutSec']);
        $this->assertSame(512, $clamped['memoryMb']);
        $this->assertSame(1.0, $clamped['cpu']);
        $this->assertSame(128, $clamped['pids']);
        $this->assertSame(50, $clamped['outputMb']);
    }

    private function config(string $url, string $token): ComputeConfig
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        return new ComputeConfig($repo, $url, $token);
    }
}
