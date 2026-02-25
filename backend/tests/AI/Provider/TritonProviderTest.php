<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\TritonProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for TritonProvider.
 */
class TritonProviderTest extends TestCase
{
    private TritonProvider $provider;
    private TritonProvider $unavailableProvider;

    protected function setUp(): void
    {
        // Provider with empty URL = unavailable (no gRPC connection attempt)
        $this->unavailableProvider = new TritonProvider(
            new NullLogger(),
            '',
        );

        // Provider with a fake URL - client will be created but can't connect
        $this->provider = new TritonProvider(
            new NullLogger(),
            'localhost:9999',
        );
    }

    public function testMetadata(): void
    {
        $this->assertEquals('triton', $this->provider->getName());
        $this->assertEquals('NVIDIA Triton', $this->provider->getDisplayName());
        $this->assertStringContainsString('gRPC', $this->provider->getDescription());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->provider->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('embedding', $capabilities);
    }

    public function testIsAvailableWithServerUrl(): void
    {
        $this->assertTrue($this->provider->isAvailable());
    }

    public function testIsUnavailableWithoutServerUrl(): void
    {
        $this->assertFalse($this->unavailableProvider->isAvailable());

        $status = $this->unavailableProvider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('not initialized', $status['error']);
    }

    public function testGetDimensionsBgeM3(): void
    {
        $this->assertEquals(1024, $this->provider->getDimensions('bge-m3'));
    }

    public function testGetDimensionsUnknownModel(): void
    {
        $this->assertEquals(1024, $this->provider->getDimensions('unknown-model'));
    }

    public function testChatThrowsWhenUnavailable(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not initialized');

        $this->unavailableProvider->chat([['role' => 'user', 'content' => 'hello']], ['model' => 'test']);
    }

    public function testChatStreamThrowsWhenUnavailable(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not initialized');

        $this->unavailableProvider->chatStream(
            [['role' => 'user', 'content' => 'hello']],
            function () {},
            ['model' => 'test']
        );
    }

    public function testEmbedThrowsWhenUnavailable(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not initialized');

        $this->unavailableProvider->embed('hello', ['model' => 'bge-m3']);
    }

    public function testBuildChunkMapsChannelsCorrectly(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('buildChunk');

        // analysis -> reasoning
        $result = $method->invoke($this->provider, 'thinking...', 'analysis');
        $this->assertEquals('reasoning', $result['type']);
        $this->assertEquals('thinking...', $result['content']);

        // commentary -> reasoning
        $result = $method->invoke($this->provider, 'hmm...', 'commentary');
        $this->assertEquals('reasoning', $result['type']);

        // final -> content
        $result = $method->invoke($this->provider, 'answer', 'final');
        $this->assertEquals('content', $result['type']);
        $this->assertEquals('answer', $result['content']);

        // content -> content
        $result = $method->invoke($this->provider, 'text', 'content');
        $this->assertEquals('content', $result['type']);

        // unknown -> content (default)
        $result = $method->invoke($this->provider, 'text', 'unknown');
        $this->assertEquals('content', $result['type']);
    }

    public function testDecodeFp32ArrayEmpty(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('decodeFp32Array');

        $result = $method->invoke($this->provider, '');
        $this->assertEquals([], $result);
    }

    public function testDecodeFp32ArrayValid(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('decodeFp32Array');

        // Pack 3 known floats as little-endian FP32
        $rawData = pack('g', 1.0).pack('g', 0.5).pack('g', -0.25);
        $result = $method->invoke($this->provider, $rawData);

        $this->assertCount(3, $result);
        $this->assertEqualsWithDelta(1.0, $result[0], 1e-6);
        $this->assertEqualsWithDelta(0.5, $result[1], 1e-6);
        $this->assertEqualsWithDelta(-0.25, $result[2], 1e-6);
    }

    public function testDecodeFp32ArrayTruncatesIncompleteBytes(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('decodeFp32Array');

        // 5 bytes = 1 complete float + 1 incomplete byte (should be ignored)
        $rawData = pack('g', 1.0)."\x00";
        $result = $method->invoke($this->provider, $rawData);

        $this->assertCount(1, $result);
        $this->assertEqualsWithDelta(1.0, $result[0], 1e-6);
    }

    public function testEmbedBatchDelegatesToEmbed(): void
    {
        // embedBatch on unavailable provider should throw on first text
        $this->expectException(ProviderException::class);

        $this->unavailableProvider->embedBatch(['hello', 'world'], ['model' => 'bge-m3']);
    }

    public function testRequiredEnvVars(): void
    {
        $envVars = $this->provider->getRequiredEnvVars();

        $this->assertArrayHasKey('TRITON_SERVER_URL', $envVars);
        $this->assertTrue($envVars['TRITON_SERVER_URL']['required']);
    }
}
