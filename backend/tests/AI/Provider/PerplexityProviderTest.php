<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\PerplexityProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class PerplexityProviderTest extends TestCase
{
    public function testMetadataAndKeyStatus(): void
    {
        $withKey = new PerplexityProvider(new NullLogger(), 'test-key');
        $this->assertSame('perplexity', $withKey->getName());
        $this->assertSame('Perplexity', $withKey->getDisplayName());
        $this->assertContains('chat', $withKey->getCapabilities());
        $this->assertSame('sonar', $withKey->getDefaultModels()['chat'] ?? null);
        $this->assertTrue($withKey->isAvailable());
        $this->assertTrue($withKey->getStatus()['healthy']);
        $this->assertArrayHasKey('PERPLEXITY_API_KEY', $withKey->getRequiredEnvVars());

        $without = new PerplexityProvider(new NullLogger());
        $this->assertFalse($without->isAvailable());
        $this->assertFalse($without->getStatus()['healthy']);
        $this->assertStringContainsString('not configured', (string) $without->getStatus()['error']);
    }

    public function testChatWithoutKeyThrows(): void
    {
        $provider = new PerplexityProvider(new NullLogger());

        $this->expectException(ProviderException::class);
        $provider->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'sonar']);
    }

    public function testChatRequiresModel(): void
    {
        $provider = new PerplexityProvider(new NullLogger(), 'test-key');

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');
        $provider->chat([['role' => 'user', 'content' => 'hi']]);
    }
}
