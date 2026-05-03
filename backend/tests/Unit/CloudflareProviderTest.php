<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Exception\ProviderException;
use App\AI\Provider\CloudflareProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class CloudflareProviderTest extends TestCase
{
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testGetNameReturnsCloudflare(): void
    {
        $provider = $this->createProvider();
        $this->assertSame('cloudflare', $provider->getName());
    }

    public function testGetDisplayName(): void
    {
        $provider = $this->createProvider();
        $this->assertSame('Cloudflare Workers AI', $provider->getDisplayName());
    }

    public function testGetCapabilitiesReturnsEmbedding(): void
    {
        $provider = $this->createProvider();
        $this->assertSame(['embedding'], $provider->getCapabilities());
    }

    public function testGetDefaultModels(): void
    {
        $provider = $this->createProvider();
        $this->assertSame(['embedding' => '@cf/baai/bge-m3'], $provider->getDefaultModels());
    }

    public function testGetDimensionsReturns1024(): void
    {
        $provider = $this->createProvider();
        $this->assertSame(1024, $provider->getDimensions('@cf/baai/bge-m3'));
        $this->assertSame(1024, $provider->getDimensions('@cf/qwen/qwen3-embedding-0.6b'));
        $this->assertSame(1024, $provider->getDimensions('any-model'));
    }

    public function testIsAvailableWhenConfigured(): void
    {
        $provider = $this->createProvider();
        $this->assertTrue($provider->isAvailable());
    }

    public function testIsNotAvailableWithoutAccountId(): void
    {
        $provider = $this->createProvider(accountId: '');
        $this->assertFalse($provider->isAvailable());
    }

    public function testIsNotAvailableWithoutToken(): void
    {
        $provider = $this->createProvider(apiToken: '');
        $this->assertFalse($provider->isAvailable());
    }

    public function testGetStatusHealthyWhenAvailable(): void
    {
        $provider = $this->createProvider();
        $status = $provider->getStatus();
        $this->assertTrue($status['healthy']);
    }

    public function testGetStatusUnhealthyWhenUnavailable(): void
    {
        $provider = $this->createProvider(accountId: '');
        $status = $provider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertNotEmpty($status['error']);
    }

    public function testEmbedThrowsWhenUnavailable(): void
    {
        $provider = $this->createProvider(accountId: '');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/not configured/i');

        $provider->embed('test text');
    }

    public function testEmbedBatchReturnsEmptyForEmptyInput(): void
    {
        $provider = $this->createProvider();
        $result = $provider->embedBatch([]);

        $this->assertSame([], $result['embeddings']);
    }

    private function createProvider(
        string $accountId = 'test-account',
        string $apiToken = 'test-token',
    ): CloudflareProvider {
        return new CloudflareProvider(
            logger: $this->logger,
            accountId: $accountId,
            apiToken: $apiToken,
        );
    }
}
