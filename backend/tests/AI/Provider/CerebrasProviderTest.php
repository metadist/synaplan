<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\CerebrasProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Unit tests for CerebrasProvider.
 *
 * Chat/vision run through the openai-php client built internally, so the
 * request shape is asserted through the protected builders — matching the
 * Meta and TrustedTokens unit-test pattern.
 */
class CerebrasProviderTest extends TestCase
{
    public function testMetadata(): void
    {
        $provider = $this->makeProvider();

        $this->assertSame('cerebras', $provider->getName());
        $this->assertSame('Cerebras', $provider->getDisplayName());
        $this->assertTrue($provider->isAvailable());
        $this->assertStringContainsString('Cerebras', $provider->getDescription());
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

        $this->assertSame('qwen-3.8-27b', $defaults['chat']);
        $this->assertSame('qwen-3.8-27b', $defaults['vision']);
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

        $this->assertArrayHasKey('CEREBRAS_API_KEY', $vars);
        $this->assertTrue($vars['CEREBRAS_API_KEY']['required']);
        $this->assertStringContainsString('cloud.cerebras.ai', $vars['CEREBRAS_API_KEY']['hint']);
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
            ['model' => 'qwen-3.8-27b'],
        );
    }

    public function testThinkingOffDisablesQwenReasoning(): void
    {
        $request = $this->buildChatOptions(['model' => 'qwen-3.8-27b', 'reasoning' => false]);

        $this->assertSame('none', $request['reasoning_effort']);
    }

    public function testThinkingOffLowersGptOssBecauseNoneIsRejected(): void
    {
        $request = $this->buildChatOptions(['model' => 'gpt-oss-120b', 'reasoning' => false]);

        $this->assertSame('low', $request['reasoning_effort']);
    }

    public function testThinkingOnUsesTheCatalogDefault(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'gpt-oss-120b',
            'reasoning' => true,
            'modelConfig' => ['reasoning_effort_default' => 'medium'],
        ]);

        $this->assertSame('medium', $request['reasoning_effort']);
    }

    public function testExplicitNoneIsDroppedForGptOss(): void
    {
        $request = $this->buildChatOptions(['model' => 'gpt-oss-120b', 'reasoning_effort' => 'none']);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testNoReasoningSignalLeavesTheCerebrasDefault(): void
    {
        $request = $this->buildChatOptions(['model' => 'qwen-3.8-27b']);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testReasoningEffortIsSkippedWhenTheRowHasNoReasoningFeature(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'qwen-3.8-27b',
            'reasoning' => true,
            'modelFeatures' => ['vision'],
        ]);

        $this->assertArrayNotHasKey('reasoning_effort', $request);
    }

    public function testStructuredOutputDropsToolsBecauseGptOssRejectsTheCombination(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'gpt-oss-120b',
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
            'tools' => [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]],
            'tool_choice' => 'auto',
        ]);

        $this->assertSame('json_schema', $request['response_format']['type']);
        $this->assertSame('sort_result', $request['response_format']['json_schema']['name']);
        $this->assertArrayNotHasKey('tools', $request);
        $this->assertArrayNotHasKey('tool_choice', $request);
    }

    public function testToolsAreKeptWithoutStructuredOutput(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'qwen-3.8-27b',
            'tools' => [['type' => 'function', 'function' => ['name' => 'lookup', 'parameters' => ['type' => 'object']]]],
        ]);

        $this->assertArrayHasKey('tools', $request);
    }

    public function testVisionTurnsQwenReasoningOff(): void
    {
        $this->assertSame(['reasoning_effort' => 'none'], $this->visionRequestOptions('qwen-3.8-27b'));
        $this->assertSame([], $this->visionRequestOptions('gpt-oss-120b'));
    }

    public function testStreamedReasoningIsReadFromTheReasoningField(): void
    {
        $provider = $this->makeProvider();
        $method = (new \ReflectionClass($provider))->getMethod('streamedReasoning');

        $this->assertSame('thinking', $method->invoke($provider, ['choices' => [['delta' => ['reasoning' => 'thinking']]]]));
        $this->assertNull($method->invoke($provider, ['choices' => [['delta' => ['content' => 'answer']]]]));
        $this->assertNull($method->invoke($provider, ['choices' => []]));
    }

    private function makeProvider(?string $apiKey = 'test-key'): CerebrasProvider
    {
        return new CerebrasProvider(new NullLogger(), $apiKey);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $options): array
    {
        $provider = $this->makeProvider();

        return (new \ReflectionClass($provider))->getMethod('buildChatOptions')->invoke($provider, [], $options, false);
    }

    /**
     * @return array<string, mixed>
     */
    private function visionRequestOptions(string $model): array
    {
        $provider = $this->makeProvider();

        return (new \ReflectionClass($provider))->getMethod('visionRequestOptions')->invoke($provider, $model);
    }
}
