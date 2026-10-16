<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Compute;

use App\Repository\ComputeRunRepository;
use App\Service\Compute\ComputeClient;
use App\Service\Compute\ComputeConfig;
use App\Service\Compute\ComputeFeatureStatusBuilder;
use App\Service\Compute\Contract\ComputeHealth;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Contracts\Cache\CacheInterface;

final class ComputeFeatureStatusBuilderTest extends TestCase
{
    public function testHealthyEntryCarriesTierCapacityImagesAndCounts(): void
    {
        $builder = $this->builder(
            enabled: true,
            health: $this->health(tier: 'gvisor', running: 1, queued: 2),
            runs: 12,
            failed: 1,
        );

        $entry = $builder->build();

        self::assertTrue($entry['enabled']);
        self::assertTrue($entry['reachable']);
        self::assertSame(1, $entry['protocol']);
        self::assertSame('gvisor', $entry['tier']);
        self::assertTrue($entry['tierMeetsRequirement']);
        self::assertSame(['maxConcurrent' => 2, 'running' => 1, 'queued' => 2], $entry['capacity']);
        self::assertSame([
            ['key' => 'python', 'digest' => 'a1b2c3d4e5f6'],
            ['key' => 'node', 'digest' => 'b1b2c3d4e5f6'],
        ], $entry['images']);
        self::assertSame(12, $entry['runsLast24h']);
        self::assertSame(1, $entry['failedLast24h']);
    }

    public function testFailingSidecarDegradesWithoutBreakingCounts(): void
    {
        $client = $this->createMock(ComputeClient::class);
        $client->method('health')->willThrowException(new \RuntimeException('Connection refused'));
        $builder = $this->builder(enabled: true, client: $client, runs: 4, failed: 0);

        $entry = $builder->build();

        self::assertTrue($entry['enabled']);
        self::assertFalse($entry['reachable']);
        self::assertSame('', $entry['tier']);
        self::assertFalse($entry['tierMeetsRequirement']);
        self::assertSame([], $entry['images']);
        self::assertSame(4, $entry['runsLast24h']);
        self::assertSame(0, $entry['failedLast24h']);
    }

    public function testDisabledFlagStillReportsReachability(): void
    {
        $builder = $this->builder(enabled: false, health: $this->health());

        $entry = $builder->build();

        self::assertFalse($entry['enabled']);
        self::assertTrue($entry['reachable']);
        self::assertSame('docker', $entry['tier']);
    }

    public function testUnknownTierNeverMeetsRequirement(): void
    {
        $builder = $this->builder(enabled: true, health: $this->health(tier: 'quantum'));

        $entry = $builder->build();

        self::assertTrue($entry['reachable']);
        self::assertSame('quantum', $entry['tier']);
        self::assertFalse($entry['tierMeetsRequirement']);
    }

    public function testBelowRequiredTierFailsRequirement(): void
    {
        $builder = $this->builder(enabled: true, health: $this->health(tier: 'docker'), requireTier: 'gvisor');

        $entry = $builder->build();

        self::assertTrue($entry['reachable']);
        self::assertFalse($entry['tierMeetsRequirement']);
    }

    public function testShortDigestFallsBackToTruncatedRef(): void
    {
        $health = ComputeHealth::fromJson((string) file_get_contents(
            __DIR__.'/../../../Fixtures/compute-contract/health.json'
        ));
        $builder = $this->builder(enabled: true, health: $health);

        $entry = $builder->build();

        self::assertNotEmpty($entry['images']);
        foreach ($entry['images'] as $image) {
            self::assertArrayHasKey('key', $image);
            self::assertArrayHasKey('digest', $image);
            self::assertLessThanOrEqual(25, mb_strlen($image['digest']));
        }
    }

    public function testCacheFailureDegradesToEmptyEntry(): void
    {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isSwitchedOn')->willReturn(true);
        $cache = $this->createMock(CacheInterface::class);
        $cache->method('get')->willThrowException(new \RuntimeException('Redis down'));
        $builder = new ComputeFeatureStatusBuilder(
            $config,
            $this->createStub(ComputeClient::class),
            $this->createStub(ComputeRunRepository::class),
            $cache,
            new NullLogger()
        );

        $entry = $builder->build();

        self::assertTrue($entry['enabled']);
        self::assertFalse($entry['reachable']);
        self::assertSame(0, $entry['runsLast24h']);
    }

    private function health(string $tier = 'docker', int $running = 0, int $queued = 0): ComputeHealth
    {
        return ComputeHealth::fromJson((string) json_encode([
            'protocol' => 1,
            'tier' => $tier,
            'images' => [
                ['key' => 'python', 'ref' => 'ghcr.io/metadist/synaplan-compute-python@sha256:a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6'],
                ['key' => 'node', 'ref' => 'ghcr.io/metadist/synaplan-compute-node@sha256:b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6b1b2c3d4e5f6'],
            ],
            'capacity' => ['maxConcurrent' => 2, 'running' => $running, 'queued' => $queued],
            'caps' => ['timeoutSec' => 300, 'memoryMb' => 2048, 'cpu' => 2.0, 'pids' => 256, 'outputMb' => 200],
            'features' => ['workspaces' => true, 'egress' => false],
        ], \JSON_THROW_ON_ERROR));
    }

    private function builder(
        bool $enabled,
        ?ComputeHealth $health = null,
        ?ComputeClient $client = null,
        int $runs = 0,
        int $failed = 0,
        string $requireTier = 'docker',
    ): ComputeFeatureStatusBuilder {
        $config = $this->createStub(ComputeConfig::class);
        $config->method('isSwitchedOn')->willReturn($enabled);
        $config->method('requireTier')->willReturn($requireTier);
        if (null === $client) {
            $client = $this->createStub(ComputeClient::class);
            $client->method('health')->willReturn($health ?? $this->health());
        }
        $runsRepo = $this->createStub(ComputeRunRepository::class);
        $runsRepo->method('countSince')->willReturn($runs);
        $runsRepo->method('countFailedSince')->willReturn($failed);

        return new ComputeFeatureStatusBuilder($config, $client, $runsRepo, new ArrayAdapter(), new NullLogger());
    }
}
