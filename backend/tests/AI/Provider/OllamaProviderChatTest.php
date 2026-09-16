<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\OllamaProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for OllamaProvider structured-output request shaping (Phase 2b).
 *
 * Ollama's `chat()` goes through the ArdaGnsrn SDK (parameters forwarded
 * unfiltered — asserted here via reflection on the private request-body
 * builder), while `chatStream()` builds its JSON body directly and posts it
 * via Symfony's HttpClient — the "SDK vs raw HTTP divergence" the plan calls
 * out, verified with a {@see MockHttpClient} instead.
 */
final class OllamaProviderChatTest extends TestCase
{
    public function testChatRequestBodyMergesStructuredOutputAsFormat(): void
    {
        $body = $this->buildChatRequestBody([], [
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ], 'llama3.2');

        $this->assertSame(['type' => 'object'], $body['format']);
    }

    public function testChatRequestBodyWithoutStructuredOutputOmitsFormat(): void
    {
        $body = $this->buildChatRequestBody([], [], 'llama3.2');

        $this->assertArrayNotHasKey('format', $body);
    }

    public function testChatStreamRequestMergesStructuredOutputAsFormat(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse(json_encode(['message' => ['content' => ''], 'done' => true]));
        });

        $provider = $this->providerWithModel($client, 'llama3.2');
        $provider->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            static fn () => null,
            ['model' => 'llama3.2', 'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object'])],
        );

        $this->assertSame(['type' => 'object'], $captured['format']);
    }

    public function testChatStreamRequestWithoutStructuredOutputOmitsFormat(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse(json_encode(['message' => ['content' => ''], 'done' => true]));
        });

        $provider = $this->providerWithModel($client, 'llama3.2');
        $provider->chatStream([['role' => 'user', 'content' => 'hi']], static fn () => null, ['model' => 'llama3.2']);

        $this->assertArrayNotHasKey('format', $captured);
    }

    /**
     * `chatStream()` checks the model is actually pulled before streaming
     * (see {@see OllamaProvider::getAvailableModels()}), which otherwise
     * reaches out over the network. Override it the same way
     * {@see OllamaProviderHasModelTest} does so the test stays offline.
     */
    private function providerWithModel(MockHttpClient $httpClient, string $model): OllamaProvider
    {
        return new class(new NullLogger(), 'http://ollama.invalid:11434', $httpClient, $model) extends OllamaProvider {
            public function __construct(
                NullLogger $logger,
                string $baseUrl,
                MockHttpClient $httpClient,
                private readonly string $model,
            ) {
                parent::__construct($logger, $baseUrl, $httpClient);
            }

            public function getAvailableModels(): array
            {
                return [$this->model];
            }
        };
    }

    /**
     * @param list<array<string, mixed>> $messages
     * @param array<string, mixed>       $options
     *
     * @return array<string, mixed>
     */
    private function buildChatRequestBody(array $messages, array $options, string $model): array
    {
        $provider = new OllamaProvider(new NullLogger(), 'http://ollama.invalid:11434', new MockHttpClient());

        return (new \ReflectionClass($provider))->getMethod('buildChatRequestBody')->invoke($provider, $messages, $options, $model);
    }
}
