<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\AI\Credential\OpenAiCompatibleEndpointRegistry;
use App\AI\Exception\ProviderException;
use App\AI\Provider\OpenAICompatibleProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class OpenAICompatibleProviderTest extends TestCase
{
    private OpenAiCompatibleEndpointRegistry&Stub $registry;
    private OpenAICompatibleProvider $provider;

    protected function setUp(): void
    {
        $this->registry = $this->createStub(OpenAiCompatibleEndpointRegistry::class);
        $this->provider = new OpenAICompatibleProvider($this->registry, new NullLogger(), '/tmp');
    }

    public function testName(): void
    {
        $this->assertSame('openaicompatible', $this->provider->getName());
        $this->assertSame('OpenAI Compatible', $this->provider->getDisplayName());
    }

    public function testCapabilities(): void
    {
        $this->assertSame(['chat', 'embedding', 'vision'], $this->provider->getCapabilities());
    }

    public function testAvailabilityDelegatesToRegistry(): void
    {
        $this->registry->method('hasAnyEndpoint')->willReturn(true);
        $this->assertTrue($this->provider->isAvailable());
    }

    public function testUnavailableWhenNoEndpoint(): void
    {
        $this->registry->method('hasAnyEndpoint')->willReturn(false);
        $this->assertFalse($this->provider->isAvailable());

        $status = $this->provider->getStatus();
        $this->assertFalse($status['healthy']);
    }

    public function testChatRequiresModel(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], []);
    }

    public function testChatFailsWhenNoEndpointResolved(): void
    {
        $this->registry->method('resolveForModel')->willReturn(null);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('No OpenAI-compatible endpoint resolved');
        $this->provider->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'foo']);
    }

    public function testEmbedFailsWhenNoEndpointResolved(): void
    {
        $this->registry->method('resolveForModel')->willReturn(null);

        $this->expectException(ProviderException::class);
        $this->provider->embed('text', ['model' => 'foo']);
    }
}
