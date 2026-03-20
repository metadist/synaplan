<?php

namespace App\Tests\AI\Provider;

use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Ensures Imagen uses the Gemini API when only GOOGLE_GEMINI_API_KEY is configured,
 * even if GOOGLE_CLOUD_PROJECT_ID is set (OAuth project ID is not a Vertex bearer token).
 */
final class GoogleProviderImagenRoutingTest extends TestCase
{
    public function testImagenUsesGenerativelanguageWhenProjectIdWithoutVertexToken(): void
    {
        $capturedUrl = '';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'predictions' => [
                ['bytesBase64Encoded' => 'ZmFrZQ==', 'mimeType' => 'image/png'],
            ],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(function (string $method, string $url) use (&$capturedUrl, $response) {
            $capturedUrl = $url;

            return $response;
        });

        $provider = new GoogleProvider(
            new NullLogger(),
            $httpClient,
            'gemini-api-key',
            'gen-lang-client-0741590758',
            'us-central1',
            '/tmp/uploads',
            null,
        );

        $images = $provider->generateImage('A red car', ['model' => 'imagen-4.0-generate-001']);

        $this->assertStringContainsString('generativelanguage.googleapis.com', $capturedUrl);
        $this->assertStringContainsString('imagen-4.0-generate-001:predict', $capturedUrl);
        $this->assertNotEmpty($images);
        $this->assertStringStartsWith('data:image/png;base64,', $images[0]['url']);
    }

    public function testImagenUsesVertexWhenProjectAndVertexTokenSet(): void
    {
        $capturedUrl = '';

        $response = $this->createMock(ResponseInterface::class);
        $response->method('toArray')->willReturn([
            'predictions' => [
                ['bytesBase64Encoded' => 'ZmFrZQ==', 'mimeType' => 'image/png'],
            ],
        ]);

        $httpClient = $this->createMock(HttpClientInterface::class);
        $httpClient->method('request')->willReturnCallback(function (string $method, string $url) use (&$capturedUrl, $response) {
            $capturedUrl = $url;

            return $response;
        });

        $provider = new GoogleProvider(
            new NullLogger(),
            $httpClient,
            'gemini-api-key',
            'my-gcp-project',
            'us-central1',
            '/tmp/uploads',
            'oauth-access-token-value',
        );

        $provider->generateImage('A blue boat', ['model' => 'imagen-4.0-generate-001']);

        $this->assertStringContainsString('aiplatform.googleapis.com', $capturedUrl);
        $this->assertStringContainsString('/projects/my-gcp-project/locations/us-central1/', $capturedUrl);
    }
}
