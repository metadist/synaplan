<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\TrustedTokensProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for TrustedTokensProvider.
 *
 * Chat/vision run through the openai-php client built internally, so only
 * metadata and preconditions (missing model / missing API key) are asserted
 * here — matching the Mistral/Groq unit-test pattern.
 */
class TrustedTokensProviderTest extends TestCase
{
    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('trustedtokens', $provider->getName());
        $this->assertSame('TrustedTokens', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
        $this->assertStringContainsString('German', $provider->getDescription());
    }

    public function testCapabilities(): void
    {
        $capabilities = $this->makeProvider()->getCapabilities();

        $this->assertContains('chat', $capabilities);
        $this->assertContains('vision', $capabilities);
        $this->assertNotContains('speech_to_text', $capabilities);
    }

    public function testDefaultModels(): void
    {
        $defaults = $this->makeProvider()->getDefaultModels();

        $this->assertSame('zai-org/GLM-5.2', $defaults['chat']);
        $this->assertSame('Qwen/Qwen3.6-35B-A3B-FP8', $defaults['vision']);
    }

    public function testStatusHealthyWhenConfigured(): void
    {
        $this->assertTrue($this->makeProvider()->getStatus()['healthy']);
    }

    public function testProviderUnavailableWithoutApiKey(): void
    {
        $provider = $this->makeProvider(apiKey: null);

        $this->assertFalse($provider->isAvailable());
        $status = $provider->getStatus();
        $this->assertFalse($status['healthy']);
        $this->assertStringContainsString('not configured', $status['error']);
    }

    public function testRequiredEnvVars(): void
    {
        $vars = $this->makeProvider()->getRequiredEnvVars();

        $this->assertArrayHasKey('TRUSTEDTOKENS_API_KEY', $vars);
        $this->assertTrue($vars['TRUSTEDTOKENS_API_KEY']['required']);
    }

    public function testChatRequiresModel(): void
    {
        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('Model must be specified');

        $this->makeProvider()->chat([['role' => 'user', 'content' => 'hi']], []);
    }

    public function testChatRequiresApiKey(): void
    {
        $this->expectException(ProviderException::class);

        $this->makeProvider(apiKey: null)->chat(
            [['role' => 'user', 'content' => 'hi']],
            ['model' => 'zai-org/GLM-5.2'],
        );
    }

    private function makeProvider(?string $apiKey = 'test-key'): TrustedTokensProvider
    {
        return new TrustedTokensProvider(new NullLogger(), $apiKey);
    }
}
