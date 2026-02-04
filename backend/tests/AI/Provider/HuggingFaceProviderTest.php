<?php

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\HuggingFaceProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Unit tests for HuggingFaceProvider.
 */
class HuggingFaceProviderTest extends TestCase
{
    private $httpClient;

    protected function setUp(): void
    {
        $this->httpClient = $this->createMock(HttpClientInterface::class);
    }

    public function testCapabilities(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            'test-key'
        );

        $capabilities = $provider->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('embedding', $capabilities);
        $this->assertContains('image_generation', $capabilities);
        $this->assertContains('video_generation', $capabilities);
    }

    public function testMetadata(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            'test-key'
        );

        $this->assertEquals('huggingface', $provider->getName());
        $this->assertEquals('HuggingFace', $provider->getDisplayName());
        $this->assertStringContainsString('Unified API', $provider->getDescription());
        $this->assertTrue($provider->isAvailable());
    }

    public function testProviderUnavailableWithoutApiKey(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            null
        );

        $this->assertFalse($provider->isAvailable());

        $status = $provider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('not configured', $status['error']);
    }

    public function testChatThrowsExceptionWithoutApiKey(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            null
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HUGGINGFACE_API_KEY');

        $provider->chat([['role' => 'user', 'content' => 'hello']], ['model' => 'test']);
    }

    public function testEmbedThrowsExceptionWithoutApiKey(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            null
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HUGGINGFACE_API_KEY');

        $provider->embed('hello', ['model' => 'test']);
    }

    public function testGenerateImageThrowsExceptionWithoutApiKey(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            null
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HUGGINGFACE_API_KEY');

        $provider->generateImage('hello', ['model' => 'test']);
    }

    public function testGenerateVideoThrowsExceptionWithoutApiKey(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            null
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HUGGINGFACE_API_KEY');

        $provider->generateVideo('hello', ['model' => 'test']);
    }

    public function testEditImageThrowsExceptionWithoutApiKey(): void
    {
        $provider = new HuggingFaceProvider(
            $this->httpClient,
            new NullLogger(),
            null
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('HUGGINGFACE_API_KEY');

        $provider->editImage('test.png', 'mask.png', 'hello');
    }
}
