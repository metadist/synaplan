<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\TritonProvider;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testEmbedBatchThrowsWhenUnavailable(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('not initialized');

        $this->unavailableProvider->embedBatch(['hello', 'world'], ['model' => 'bge-m3']);
    }

    public function testEmbedBatchReturnsEmptyForEmptyInput(): void
    {
        $result = $this->provider->embedBatch([], ['model' => 'bge-m3']);

        $this->assertSame([], $result['embeddings']);
        $this->assertSame(0, $result['usage']['total_tokens']);
    }

    public function testEmbedBatchRequiresModelOption(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Embedding model must be specified');

        $this->provider->embedBatch(['hello'], []);
    }

    /**
     * The batched request packs all texts into a single [N, 1] BYTES tensor,
     * so Triton runs one forward pass per chunk. Verify shape + payload for
     * batch sizes 1, 32 and >32 (33).
     */
    #[DataProvider('batchSizeProvider')]
    public function testCreateBatchEmbedInferRequestShape(int $count): void
    {
        $texts = [];
        for ($i = 0; $i < $count; ++$i) {
            $texts[] = 'text-'.$i;
        }

        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('createBatchEmbedInferRequest');

        /** @var \Inference\ModelInferRequest $request */
        $request = $method->invoke($this->provider, $texts, 'bge-m3');

        $this->assertEquals('bge-m3', $request->getModelName());

        $inputs = iterator_to_array($request->getInputs());
        $this->assertCount(1, $inputs);

        $input = $inputs[0];
        $this->assertEquals('text_input', $input->getName());
        $this->assertEquals('BYTES', $input->getDatatype());
        $this->assertEquals([$count, 1], iterator_to_array($input->getShape()));

        $bytes = iterator_to_array($input->getContents()->getBytesContents());
        $this->assertCount($count, $bytes);
        $this->assertSame($texts, $bytes);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function batchSizeProvider(): array
    {
        return [
            'single' => [1],
            'full batch' => [32],
            'over batch (chunked)' => [33],
        ];
    }

    public function testSplitFlatEmbeddingsSplitsInInputOrder(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('splitFlatEmbeddings');

        // 3 vectors of dimension 2, concatenated row-major.
        $flat = [1.0, 2.0, 3.0, 4.0, 5.0, 6.0];
        $result = $method->invoke($this->provider, $flat, 3);

        $this->assertSame([[1.0, 2.0], [3.0, 4.0], [5.0, 6.0]], $result);
    }

    public function testSplitFlatEmbeddingsSingleVector(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('splitFlatEmbeddings');

        $flat = [0.1, 0.2, 0.3, 0.4];
        $result = $method->invoke($this->provider, $flat, 1);

        $this->assertSame([[0.1, 0.2, 0.3, 0.4]], $result);
    }

    public function testSplitFlatEmbeddingsThrowsOnIndivisibleOutput(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('splitFlatEmbeddings');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not divisible');

        // 5 floats cannot be evenly split across 2 texts.
        $method->invoke($this->provider, [1.0, 2.0, 3.0, 4.0, 5.0], 2);
    }

    public function testSplitFlatEmbeddingsHandlesFullAndOverBatch(): void
    {
        $reflection = new \ReflectionClass($this->provider);
        $method = $reflection->getMethod('splitFlatEmbeddings');

        // Simulate a 32- and a 33-vector output with dimension 4.
        foreach ([32, 33] as $count) {
            $flat = range(1, $count * 4);
            $result = $method->invoke($this->provider, $flat, $count);

            $this->assertCount($count, $result);
            $this->assertCount(4, $result[0]);
            $this->assertSame([1, 2, 3, 4], $result[0]);
            $this->assertSame([$count * 4 - 3, $count * 4 - 2, $count * 4 - 1, $count * 4], $result[$count - 1]);
        }
    }

    public function testRequiredEnvVars(): void
    {
        $envVars = $this->provider->getRequiredEnvVars();

        $this->assertArrayHasKey('TRITON_SERVER_URL', $envVars);
        $this->assertTrue($envVars['TRITON_SERVER_URL']['required']);
    }
}
