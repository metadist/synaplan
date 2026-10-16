<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Repository\ConfigRepository;
use App\Service\Compute\ComputeConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ComputeConfigTierGateTest extends TestCase
{
    public function testLowerTierDisables(): void
    {
        $config = $this->config(requireTier: 'gvisor', tier: 'docker');

        self::assertFalse($config->isEnabled());
    }

    public function testUnreachableDisables(): void
    {
        $repo = $this->configRepo('docker');
        $http = new MockHttpClient(static function (): MockResponse {
            throw new \RuntimeException('Connection refused');
        });
        $config = new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b', null, null, $http, new ArrayAdapter());

        self::assertFalse($config->isEnabled());
    }

    public function testMatchingTierKeepsEnabled(): void
    {
        $config = $this->config(requireTier: 'gvisor', tier: 'gvisor');

        self::assertTrue($config->isEnabled());
    }

    public function testStrongerTierKeepsEnabled(): void
    {
        $config = $this->config(requireTier: 'gvisor', tier: 'microvm');

        self::assertTrue($config->isEnabled());
    }

    public function testUnknownTierDisables(): void
    {
        $config = $this->config(requireTier: 'docker', tier: 'quantum');

        self::assertFalse($config->isEnabled());
    }

    public function testInvalidRequirementFailsClosedToStrictest(): void
    {
        $config = $this->config(requireTier: 'quantum', tier: 'gvisor');

        self::assertSame('microvm', $config->requireTier());
        self::assertFalse($config->isEnabled());
    }

    public function testMissingRequirementDefaultsToDocker(): void
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturn(null);
        $config = new ComputeConfig($repo, 'http://compute:8080', 'token-token-token-token-token-32b');

        self::assertSame('docker', $config->requireTier());
    }

    public function testTierCacheIsScopedToTheSidecarUrl(): void
    {
        $cache = new ArrayAdapter();
        $docker = $this->configOn('http://compute-a:8080', 'docker', $cache);
        $gvisor = $this->configOn('http://compute-b:8080', 'gvisor', $cache);

        self::assertFalse($docker->isEnabled());
        // Must probe B instead of inheriting A's cached docker tier.
        self::assertTrue($gvisor->isEnabled());
    }

    public function testTierHelpers(): void
    {
        self::assertTrue(ComputeConfig::tierAtLeast('microvm', 'gvisor'));
        self::assertTrue(ComputeConfig::tierAtLeast('docker', 'docker'));
        self::assertFalse(ComputeConfig::tierAtLeast('docker', 'gvisor'));
        self::assertFalse(ComputeConfig::tierAtLeast('quantum', 'docker'));
        self::assertSame('Standard isolation', ComputeConfig::tierDisplayName('docker'));
        self::assertSame('Strong isolation', ComputeConfig::tierDisplayName('gvisor'));
        self::assertSame('Virtual machine isolation', ComputeConfig::tierDisplayName('microvm'));
    }

    private function config(string $requireTier, string $tier): ComputeConfig
    {
        return $this->configOn('http://compute:8080', $tier, new ArrayAdapter(), $requireTier);
    }

    private function configOn(string $url, string $tier, ArrayAdapter $cache, string $requireTier = 'gvisor'): ComputeConfig
    {
        $http = new MockHttpClient([
            new MockResponse((string) json_encode(['tier' => $tier]), ['http_code' => 200]),
        ]);

        return new ComputeConfig(
            $this->configRepo($requireTier),
            $url,
            'token-token-token-token-token-32b',
            null,
            null,
            $http,
            $cache
        );
    }

    private function configRepo(string $requireTier): ConfigRepository
    {
        $repo = $this->createStub(ConfigRepository::class);
        $repo->method('getValue')->willReturnCallback(
            static fn (int $ownerId, string $group, string $setting): ?string => match ($setting) {
                'ENABLED' => '1',
                'REQUIRE_TIER' => $requireTier,
                default => null,
            }
        );

        return $repo;
    }
}
