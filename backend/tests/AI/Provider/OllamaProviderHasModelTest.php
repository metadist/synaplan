<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\OllamaProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;

/**
 * isAvailable() only proves the Ollama server answers. A stock install has a
 * reachable Ollama holding nothing but the embedding model (the compose stack
 * pulls bge-m3 and only adds a chat model when ENABLE_LOCAL_GPT_OSS=true), so
 * anything that may route CHAT to Ollama must check the concrete model —
 * otherwise onboarding binds chat to a model that was never downloaded.
 */
final class OllamaProviderHasModelTest extends TestCase
{
    /**
     * @param list<string> $models
     */
    private function provider(array $models): OllamaProvider
    {
        return new class(new NullLogger(), 'http://ollama.invalid:11434', new MockHttpClient(), $models) extends OllamaProvider {
            /** @param list<string> $models */
            public function __construct(
                NullLogger $logger,
                string $baseUrl,
                MockHttpClient $httpClient,
                private readonly array $models,
            ) {
                parent::__construct($logger, $baseUrl, $httpClient);
            }

            public function getAvailableModels(): array
            {
                return $this->models;
            }
        };
    }

    public function testEmbeddingOnlyServerHasNoChatModel(): void
    {
        $provider = $this->provider(['bge-m3:latest']);

        self::assertFalse($provider->hasModel('gpt-oss:120b'));
        self::assertFalse($provider->hasModel('gpt-oss:20b'));
    }

    public function testImplicitLatestTagIsMatched(): void
    {
        $provider = $this->provider(['bge-m3:latest']);

        self::assertTrue($provider->hasModel('bge-m3'));
        self::assertTrue($provider->hasModel('bge-m3:latest'));
    }

    public function testExactTagMatchIsCaseInsensitive(): void
    {
        $provider = $this->provider(['GPT-OSS:20B']);

        self::assertTrue($provider->hasModel('gpt-oss:20b'));
    }

    public function testEmptyServerAndEmptyInput(): void
    {
        self::assertFalse($this->provider([])->hasModel('gpt-oss:120b'));
        self::assertFalse($this->provider(['bge-m3:latest'])->hasModel('  '));
    }
}
