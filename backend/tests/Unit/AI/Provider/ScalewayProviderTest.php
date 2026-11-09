<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Catalog\ProviderCapability;
use App\AI\Catalog\ProviderCatalog;
use App\AI\Exception\ProviderException;
use App\AI\Provider\ScalewayProvider;
use App\Model\ModelCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The descriptor is what every table reads; this pins the values a typo
 * would silently change, and that discovery finds the class.
 */
final class ScalewayProviderTest extends TestCase
{
    public function testTheDescriptorNamesTheProvider(): void
    {
        $descriptor = ScalewayProvider::descriptor();

        self::assertSame('scaleway', $descriptor->name);
        self::assertSame('SCALEWAY_API_KEY', $descriptor->key?->envVar);
        self::assertContains('api.scaleway.ai', $descriptor->hosts());
        self::assertTrue($descriptor->hasCapability(ProviderCapability::Chat));
        self::assertSame('circle-flags:fr', $descriptor->branding->flag);
        self::assertSame('https://api.scaleway.ai/v1/chat/completions', $descriptor->chatCompletionsUrl());
    }

    public function testDiscoveryFindsTheClass(): void
    {
        self::assertTrue(ProviderCatalog::builtIn()->has('scaleway'));
    }

    public function testExplicitReasoningEffortIsForwarded(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'deepseek-v4.1-flash-1017',
            'reasoning_effort' => 'max',
        ]);

        self::assertSame('max', $request['reasoning_effort']);
    }

    public function testThinkingOffUsesTheCheapestAcceptedLevel(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'gemma-4-26b-a4b-it',
            'reasoning' => false,
        ]);

        self::assertSame('none', $request['reasoning_effort']);
    }

    public function testThinkingOnUsesTheCatalogDefault(): void
    {
        $request = $this->buildChatOptions([
            'model' => 'qwen3.8-27b',
            'reasoning' => true,
            'modelConfig' => ['reasoning_effort_default' => 'xhigh'],
        ]);

        self::assertSame('xhigh', $request['reasoning_effort']);
    }

    public function testEmbeddingErrorKeepsTheHttpStatus(): void
    {
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"bad request"}}', ['http_code' => 400]));
        $provider = new ScalewayProvider(new NullLogger(), 'test-key', http: $http);

        try {
            $provider->embedBatch(['hello'], ['model' => 'qwen3-embedding-8b']);
            self::fail('An embedding error was expected.');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->getCode());
        }
    }

    public function testTranscriptionErrorKeepsTheHttpStatus(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'scw-audio');
        self::assertNotFalse($path);
        file_put_contents($path, 'audio');
        $http = new MockHttpClient(new MockResponse('{"error":{"message":"too many"}}', ['http_code' => 429]));
        $provider = new ScalewayProvider(new NullLogger(), 'test-key', http: $http);

        try {
            $provider->transcribe($path, ['model' => 'whisper-large-v3']);
            self::fail('A transcription error was expected.');
        } catch (ProviderException $e) {
            self::assertSame(429, $e->getCode());
        } finally {
            @unlink($path);
        }
    }

    public function testLightOnOcrIsNotOfferedAsChat(): void
    {
        $rows = ModelCatalog::find('scaleway:lightonocr-3-0.8b:chat');

        self::assertCount(1, $rows);
        self::assertSame(0, $rows[0]['selectable']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function buildChatOptions(array $options): array
    {
        $provider = new ScalewayProvider(new NullLogger(), 'test-key');

        /** @var array<string, mixed> $request */
        $request = (new \ReflectionClass($provider))->getMethod('buildChatOptions')->invoke(
            $provider,
            [['role' => 'user', 'content' => 'Hi']],
            $options,
            false,
        );

        return $request;
    }
}
