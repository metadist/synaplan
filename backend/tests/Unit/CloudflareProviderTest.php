<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Exception\ProviderException;
use App\AI\Provider\CloudflareProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class CloudflareProviderTest extends TestCase
{
    private LoggerInterface&MockObject $logger;
    private HttpClientInterface&MockObject $httpClient;

    protected function setUp(): void
    {
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);
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

    public function testEmbedReturnsVector(): void
    {
        $vector = array_fill(0, 1024, 0.01);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => true,
            'result' => [
                'data' => [$vector],
                'shape' => [1, 1024],
            ],
            'errors' => [],
            'messages' => [],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/ai/run/@cf/baai/bge-m3'),
                $this->callback(function (array $options) {
                    $this->assertSame('Bearer test-token', $options['headers']['Authorization']);
                    $this->assertSame(['test input'], $options['json']['text']);

                    return true;
                })
            )
            ->willReturn($response);

        $provider = $this->createProvider();
        $result = $provider->embed('test input');

        $this->assertSame($vector, $result['embedding']);
        $this->assertArrayHasKey('usage', $result);
        $this->assertSame(0, $result['usage']['prompt_tokens']);
    }

    public function testEmbedThrowsOnApiError(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => false,
            'errors' => [['message' => 'Rate limit exceeded']],
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $provider = $this->createProvider();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/Cloudflare API error/');

        $provider->embed('test');
    }

    public function testEmbedThrowsOnEmptyResult(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => true,
            'result' => ['data' => [[]]],
        ]);

        $this->httpClient->method('request')->willReturn($response);

        $provider = $this->createProvider();

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessageMatches('/empty embedding/');

        $provider->embed('test');
    }

    public function testEmbedBatchReturnsVectors(): void
    {
        $vector1 = array_fill(0, 1024, 0.01);
        $vector2 = array_fill(0, 1024, 0.02);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => true,
            'result' => [
                'data' => [$vector1, $vector2],
                'shape' => [2, 1024],
            ],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $provider = $this->createProvider();
        $result = $provider->embedBatch(['text 1', 'text 2']);

        $this->assertCount(2, $result['embeddings']);
        $this->assertSame($vector1, $result['embeddings'][0]);
        $this->assertSame($vector2, $result['embeddings'][1]);
    }

    public function testEmbedBatchReturnsEmptyForEmptyInput(): void
    {
        $this->httpClient->expects($this->never())->method('request');

        $provider = $this->createProvider();
        $result = $provider->embedBatch([]);

        $this->assertSame([], $result['embeddings']);
    }

    public function testEmbedBatchChunksLargeBatches(): void
    {
        $vector = array_fill(0, 1024, 0.01);
        $texts = array_fill(0, 150, 'text');

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => true,
            'result' => ['data' => array_fill(0, 100, $vector)],
        ]);

        $response2 = $this->createMock(ResponseInterface::class);
        $response2->method('toArray')->willReturn([
            'success' => true,
            'result' => ['data' => array_fill(0, 50, $vector)],
        ]);

        $this->httpClient->expects($this->exactly(2))
            ->method('request')
            ->willReturnOnConsecutiveCalls($response, $response2);

        $provider = $this->createProvider();
        $result = $provider->embedBatch($texts);

        $this->assertCount(150, $result['embeddings']);
    }

    public function testEmbedUsesCustomModel(): void
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'success' => true,
            'result' => ['data' => [array_fill(0, 1024, 0.01)]],
        ]);

        $this->httpClient->expects($this->once())
            ->method('request')
            ->with(
                'POST',
                $this->stringContains('/ai/run/@cf/baai/bge-large-en-v1.5'),
                $this->anything()
            )
            ->willReturn($response);

        $provider = $this->createProvider();
        $provider->embed('test', ['model' => '@cf/baai/bge-large-en-v1.5']);
    }

    private function createProvider(
        string $accountId = 'test-account',
        string $apiToken = 'test-token',
    ): CloudflareProvider {
        return new CloudflareProvider(
            httpClient: $this->httpClient,
            logger: $this->logger,
            accountId: $accountId,
            apiToken: $apiToken,
        );
    }
}
