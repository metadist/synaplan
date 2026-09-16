<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Health\Probe;

use App\AI\Health\Probe\OllamaModelListProbe;
use App\AI\Provider\OllamaProvider;
use App\AI\Service\ProviderRegistry;
use PHPUnit\Framework\TestCase;

/**
 * #1704: an empty OLLAMA_BASE_URL is "not configured", not an outage.
 * The provider is always registered in DI, so the skip must come from the URL.
 */
final class OllamaModelListProbeTest extends TestCase
{
    public function testEmptyBaseUrlIsSkippedNotFailed(): void
    {
        $result = $this->probeFor(baseUrl: '', available: false)->probe('ollama');

        self::assertTrue($result->isSkipped(), 'An empty OLLAMA_BASE_URL must not be treated as an outage');
        self::assertFalse($result->isFailed());
        // Names the variable the operator has to set — the two skip reasons are
        // reported separately because they need different actions.
        self::assertSame('OLLAMA_BASE_URL is not configured.', $result->message);
    }

    public function testWhitespaceBaseUrlIsSkipped(): void
    {
        $result = $this->probeFor(baseUrl: "  \n", available: false)->probe('ollama');

        self::assertTrue($result->isSkipped());
        self::assertFalse($result->isFailed());
    }

    public function testMissingProviderIsSkipped(): void
    {
        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn([]);

        $result = (new OllamaModelListProbe($registry, 'http://ollama:11434'))->probe('ollama');

        self::assertTrue($result->isSkipped());
        self::assertSame('Ollama is not registered in this installation.', $result->message);
    }

    public function testConfiguredUnreachableOllamaIsFailed(): void
    {
        $result = $this->probeFor(baseUrl: 'http://ollama:11434', available: false)->probe('ollama');

        self::assertTrue($result->isFailed(), 'A configured Ollama that does not answer is still an outage');
        self::assertFalse($result->isSkipped());
        self::assertStringContainsString('not reachable', $result->message);
    }

    public function testConfiguredReachableOllamaListsModels(): void
    {
        $result = $this->probeFor(baseUrl: 'http://ollama:11434', available: true, models: ['llama3.2', 'bge-m3'])->probe('ollama');

        self::assertTrue($result->isOk());
        self::assertTrue($result->listingAuthoritative);
        self::assertSame(['llama3.2', 'bge-m3'], $result->modelIds);
    }

    public function testSupportsOnlyOllama(): void
    {
        $probe = new OllamaModelListProbe($this->createStub(ProviderRegistry::class));

        self::assertTrue($probe->supports('ollama'));
        self::assertTrue($probe->supports('Ollama'));
        self::assertFalse($probe->supports('triton'));
        self::assertFalse($probe->supports('openai'));
    }

    /**
     * @param list<string> $models
     */
    private function probeFor(string $baseUrl, bool $available, array $models = []): OllamaModelListProbe
    {
        $provider = $this->createStub(OllamaProvider::class);
        $provider->method('isAvailable')->willReturn($available);
        $provider->method('getAvailableModels')->willReturn($models);

        $registry = $this->createStub(ProviderRegistry::class);
        $registry->method('getUniqueProviders')->willReturn(['ollama' => $provider]);

        return new OllamaModelListProbe($registry, $baseUrl);
    }
}
