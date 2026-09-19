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

    public function testInvalidRequirementFallsBackToDocker(): void
    {
        $config = $this->config(requireTier: 'quantum', tier: 'docker');

        self::assertSame('docker', $config->requireTier());
        self::assertTrue($config->isEnabled());
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
        $http = new MockHttpClient([
            new MockResponse((string) json_encode(['tier' => $tier]), ['http_code' => 200]),
        ]);

        return new ComputeConfig(
            $this->configRepo($requireTier),
            'http://compute:8080',
            'token-token-token-token-token-32b',
            null,
            null,
            $http,
            new ArrayAdapter()
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
