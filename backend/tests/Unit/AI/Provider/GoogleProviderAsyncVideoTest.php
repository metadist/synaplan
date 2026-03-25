<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class GoogleProviderAsyncVideoTest extends TestCase
{
    private function createProviderWithMockResponse(array $responseData, int $statusCode = 200): GoogleProvider
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn($responseData);
        $response->method('getStatusCode')->willReturn($statusCode);
        $response->method('getContent')->willReturn(json_encode($responseData));

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturn($response);

        return new GoogleProvider(
            new NullLogger(),
            $httpClient,
            'fake-api-key',
        );
    }

    public function testStartVideoOperationSuccess(): void
    {
        $data = ['name' => 'operations/12345'];
        $provider = $this->createProviderWithMockResponse($data);

        $result = $provider->startVideoOperation('test prompt', ['model' => 'veo-3.1', 'duration' => 8]);

        $this->assertSame('operations/12345', $result['operationName']);
        $this->assertSame('veo-3.1', $result['model']);
        $this->assertSame(8, $result['duration']);
    }

    public function testStartVideoOperationMissingApiKey(): void
    {
        $httpClient = $this->createMock(HttpClientInterface::class);
        $provider = new GoogleProvider(new NullLogger(), $httpClient, '');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('API key not configured');

        $provider->startVideoOperation('test prompt');
    }

    public function testStartVideoOperationApiError(): void
    {
        $provider = $this->createProviderWithMockResponse(['error' => 'bad request'], 400);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Google Veo API error (HTTP 400)');

        $provider->startVideoOperation('test prompt');
    }

    public function testPollVideoOperationOnceNotDone(): void
    {
        $data = ['done' => false];
        $provider = $this->createProviderWithMockResponse($data);

        $result = $provider->pollVideoOperationOnce('operations/12345');

        $this->assertFalse($result['done']);
        $this->assertNull($result['videoUri']);
        $this->assertNull($result['error']);
    }

    public function testPollVideoOperationOnceDoneSuccess(): void
    {
        $data = [
            'done' => true,
            'response' => [
                'generateVideoResponse' => [
                    'generatedSamples' => [
                        ['video' => ['uri' => 'https://example.com/video.mp4']],
                    ],
                ],
            ],
        ];
        $provider = $this->createProviderWithMockResponse($data);

        $result = $provider->pollVideoOperationOnce('operations/12345');

        $this->assertTrue($result['done']);
        $this->assertSame('https://example.com/video.mp4', $result['videoUri']);
        $this->assertNull($result['error']);
    }

    public function testPollVideoOperationOnceDoneError(): void
    {
        $data = [
            'done' => true,
            'error' => [
                'message' => 'Generation failed',
                'code' => 500,
            ],
        ];
        $provider = $this->createProviderWithMockResponse($data);

        $result = $provider->pollVideoOperationOnce('operations/12345');

        $this->assertTrue($result['done']);
        $this->assertNull($result['videoUri']);
        $this->assertSame('Generation failed (code: 500)', $result['error']);
    }

    public function testPollVideoOperationOnceSafetyError(): void
    {
        $data = [
            'done' => true,
            'error' => [
                'message' => 'Content blocked due to safety guidelines',
                'code' => 400,
            ],
        ];
        $provider = $this->createProviderWithMockResponse($data);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('SAFETY');

        $provider->pollVideoOperationOnce('operations/12345');
    }
}
