<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Message\Routing;

use App\Repository\ConfigRepository;
use App\Service\Message\Routing\EmbeddingRouterConfig;
use PHPUnit\Framework\TestCase;

final class EmbeddingRouterConfigTest extends TestCase
{
    public function testIsEnabledDefaultsToFalseWhenNoRowExists(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        $config = new EmbeddingRouterConfig($repo);

        $this->assertFalse($config->isEnabled(null));
        $this->assertFalse($config->isEnabled(42));
    }

    public function testIsEnabledReadsGlobalRow(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 0 === $owner && 'EMBEDDING_ROUTER' === $group && 'ENABLED' === $setting ? '1' : null
        );

        $config = new EmbeddingRouterConfig($repo);

        $this->assertTrue($config->isEnabled(null));
        $this->assertTrue($config->isEnabled(7));
    }

    public function testPerUserRowOverridesGlobalRow(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static function (int $owner, string $group, string $setting): ?string {
                if ('EMBEDDING_ROUTER' !== $group || 'ENABLED' !== $setting) {
                    return null;
                }

                return 7 === $owner ? '0' : '1';
            }
        );

        $config = new EmbeddingRouterConfig($repo);

        $this->assertTrue($config->isEnabled(0));
        $this->assertFalse($config->isEnabled(7), 'per-user override must win over the global row');
    }

    public function testGetConfidenceThresholdDefaultsWhenRowMissing(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);

        $config = new EmbeddingRouterConfig($repo);

        $this->assertSame(0.88, $config->getConfidenceThreshold());
    }

    public function testGetConfidenceThresholdReadsConfiguredValue(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'CONFIDENCE_THRESHOLD' === $setting ? '0.75' : null
        );

        $config = new EmbeddingRouterConfig($repo);

        $this->assertSame(0.75, $config->getConfidenceThreshold());
    }

    public function testGetConfidenceThresholdClampsOutOfRangeValues(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'CONFIDENCE_THRESHOLD' === $setting ? '1.5' : null
        );

        $config = new EmbeddingRouterConfig($repo);

        $this->assertSame(1.0, $config->getConfidenceThreshold());
    }

    public function testGetConfidenceThresholdFallsBackOnNonNumericValue(): void
    {
        $repo = $this->createMock(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $owner, string $group, string $setting): ?string => 'CONFIDENCE_THRESHOLD' === $setting ? 'not-a-number' : null
        );

        $config = new EmbeddingRouterConfig($repo);

        $this->assertSame(0.88, $config->getConfidenceThreshold());
    }
}
