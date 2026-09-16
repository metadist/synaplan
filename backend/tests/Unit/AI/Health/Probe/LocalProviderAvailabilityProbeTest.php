<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health\Probe;

use App\AI\Health\Probe\LocalProviderAvailabilityProbe;
use App\AI\Interface\ProviderMetadataInterface;
use App\AI\Service\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Locks the rule that caused the "3 NVIDIA Triton model(s) failing" incident:
 * an empty TRITON_SERVER_URL is "not configured", not an outage.
 */
final class LocalProviderAvailabilityProbeTest extends TestCase
{
    public function testUnconfiguredTritonIsSkippedNotFailed(): void
    {
        $result = $this->probeFor('triton', available: false)->probe('triton');

        self::assertTrue($result->isSkipped(), 'An empty TRITON_SERVER_URL must not be treated as an outage');
        self::assertFalse($result->isFailed());
        self::assertStringContainsString('not configured', $result->message);
    }

    public function testUnconfiguredPiperIsSkippedNotFailed(): void
    {
        $result = $this->probeFor('piper', available: false)->probe('piper');

        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isFailed());
    }

    public function testConfiguredTritonIsReportedReachable(): void
    {
        $result = $this->probeFor('triton', available: true)->probe('triton');

        self::assertTrue($result->isOk());
        self::assertFalse($result->listingComplete);
        self::assertStringContainsString('reachable', $result->message);
    }

    public function testUnknownServiceIsSkipped(): void
    {
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn([]);

        $result = (new LocalProviderAvailabilityProbe($registry))->probe('triton');

        self::assertTrue($result->isSkipped());
        self::assertStringContainsString('not registered', $result->message);
    }

    public function testSupportsOnlyTritonAndPiper(): void
    {
        $probe = new LocalProviderAvailabilityProbe($this->createStub(ProviderRegistry::class));

        self::assertTrue($probe->supports('triton'));
        self::assertTrue($probe->supports('Piper'));
        self::assertFalse($probe->supports('ollama'));
        self::assertFalse($probe->supports('openai'));
    }

    private function probeFor(string $name, bool $available): LocalProviderAvailabilityProbe
    {
        $provider = $this->createStub(ProviderMetadataInterface::class);
        $provider->method('getName')->willReturn($name);
        $provider->method('getDisplayName')->willReturn('triton' === $name ? 'NVIDIA Triton' : 'Piper TTS');
        $provider->method('isAvailable')->willReturn($available);

        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn([$name => $provider]);

        return new LocalProviderAvailabilityProbe($registry);
    }
}
