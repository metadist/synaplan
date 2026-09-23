<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\MetaProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for MetaProvider.
 *
 * Chat/vision run through the openai-php client built internally, so only
 * metadata and preconditions (missing model / missing API key) are asserted
 * here — matching the TrustedTokens unit-test pattern.
 */
class MetaProviderTest extends TestCase
{
    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('meta', $provider->getName());
        $this->assertSame('Meta', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
        $this->assertStringContainsString('Meta', $provider->getDescription());
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

        $this->assertSame('muse-spark-1.3', $defaults['chat']);
        $this->assertSame('muse-spark-1.3', $defaults['vision']);
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

        $this->assertArrayHasKey('META_API_KEY', $vars);
        $this->assertTrue($vars['META_API_KEY']['required']);
        $this->assertStringContainsString('dev.meta.ai', $vars['META_API_KEY']['hint']);
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
            ['model' => 'muse-spark-1.3'],
        );
    }

    public function testChatOptionsMergeStructuredOutputAsJsonSchema(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], false);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertSame(['type' => 'object'], $request['response_format']['json_schema']['schema']);
    }

    public function testThinkingOffSendsMinimalBecauseNoneIsRejected(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning' => false,
        ], false);

        $this->assertSame('minimal', $request['reasoning_effort']);
    }

    public function testThinkingOnUsesTheCatalogDefault(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning' => true,
            'modelConfig' => ['reasoning_effort_default' => 'high'],
        ], false);

        $this->assertSame('high', $request['reasoning_effort']);
    }

    public function testExplicitNoneIsNotSent(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning_effort' => 'none',
        ], false);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testReasoningEffortIsSkippedWhenTheRowHasNoReasoningFeature(): void
    {
        $request = $this->buildChatOptions([], [
            'model' => 'muse-spark-1.3',
            'reasoning' => true,
            'modelFeatures' => ['vision'],
        ], false);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    private function makeProvider(?string $apiKey = 'test-key'): MetaProvider
    {
        return new MetaProvider(new NullLogger(), $apiKey);
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $messages, array $options, bool $stream): array
    {
        $provider = $this->makeProvider();

        return (new \ReflectionClass($provider))->getMethod('buildChatOptions')->invoke($provider, $messages, $options, $stream);
    }
}
