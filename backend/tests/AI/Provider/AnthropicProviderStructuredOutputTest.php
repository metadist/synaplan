<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\AnthropicProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Anthropic has no native JSON-schema response mode
 * (StructuredOutputDialect::ANTHROPIC_TOOL_FORCING) — a schema request is
 * sent as a single forced tool call, and the response's tool_use `input`
 * must come back to the caller through the same `content` field a normal
 * text response uses, so downstream JSON decoding stays provider-agnostic.
 */
class AnthropicProviderStructuredOutputTest extends TestCase
{
    private const API_KEY = 'test-key';

    public function testChatRequestMergesToolForcingWhenStructuredOutputRequested(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return new MockResponse((string) json_encode([
                'content' => [['type' => 'tool_use', 'name' => 'sort_classification', 'input' => ['topic' => 'general']]],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]));
        });

        $schema = new StructuredOutputSchema('sort_classification', ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']]]);

        $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'Classify this']],
            ['model' => 'claude-haiku-4-5', 'structured_output' => $schema],
        );

        $this->assertNotNull($captured);
        $this->assertSame('sort_classification', $captured['tools'][0]['name']);
        $this->assertSame(['type' => 'object', 'properties' => ['topic' => ['type' => 'string']]], $captured['tools'][0]['input_schema']);
        $this->assertSame(['type' => 'tool', 'name' => 'sort_classification'], $captured['tool_choice']);
    }

    public function testChatRequestWithoutStructuredOutputOmitsTools(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return new MockResponse((string) json_encode([
                'content' => [['type' => 'text', 'text' => 'Hello!']],
                'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
            ]));
        });

        $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'claude-haiku-4-5'],
        );

        $this->assertNotNull($captured);
        $this->assertArrayNotHasKey('tools', $captured);
        $this->assertArrayNotHasKey('tool_choice', $captured);
    }

    public function testChatReEncodesToolUseInputAsContent(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'content' => [['type' => 'tool_use', 'name' => 'sort_classification', 'input' => ['topic' => 'mediamaker', 'language' => 'en']]],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5],
        ])));

        $schema = new StructuredOutputSchema('sort_classification', ['type' => 'object']);

        $result = $this->makeProvider(httpClient: $client)->chat(
            [['role' => 'user', 'content' => 'Classify this']],
            ['model' => 'claude-haiku-4-5', 'structured_output' => $schema],
        );

        $decoded = json_decode($result['content'], true);
        $this->assertSame(['topic' => 'mediamaker', 'language' => 'en'], $decoded);
    }

    public function testChatStreamRequestMergesToolForcingWhenStructuredOutputRequested(): void
    {
        $captured = null;
        $sse = $this->buildToolUseSseStream();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $sse): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $schema = new StructuredOutputSchema('sort_classification', ['type' => 'object']);

        $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'Classify this']],
            static fn () => null,
            ['model' => 'claude-haiku-4-5', 'structured_output' => $schema],
        );

        $this->assertNotNull($captured);
        $this->assertSame('sort_classification', $captured['tools'][0]['name']);
        $this->assertSame(['type' => 'tool', 'name' => 'sort_classification'], $captured['tool_choice']);
    }

    /**
     * Tool-use streaming carries the JSON `input` as incremental
     * `input_json_delta` events (never `text_delta`) — they must forward as
     * ordinary `content` callbacks so the fragments concatenate into the
     * same complete JSON string the non-streaming path returns.
     */
    public function testChatStreamForwardsInputJsonDeltaAsContentChunks(): void
    {
        $sse = $this->buildToolUseSseStream();
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse($sse, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $received = [];
        $schema = new StructuredOutputSchema('sort_classification', ['type' => 'object']);

        $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'Classify this']],
            static function (mixed $chunk) use (&$received): void {
                $received[] = $chunk;
            },
            ['model' => 'claude-haiku-4-5', 'structured_output' => $schema],
        );

        $contentChunks = array_values(array_filter($received, static fn ($c) => 'content' === ($c['type'] ?? null)));
        $this->assertNotEmpty($contentChunks);

        $joined = implode('', array_column($contentChunks, 'content'));
        $decoded = json_decode($joined, true);
        $this->assertSame(['topic' => 'mediamaker'], $decoded);
    }

    /**
     * @param array<array{event: string, data: array<mixed>}> $events
     */
    private function buildSseStream(array $events): string
    {
        $parts = [];

        foreach ($events as $e) {
            $parts[] = 'event: '.$e['event']."\ndata: ".json_encode($e['data']);
        }

        return implode("\n\n", $parts)."\n\n";
    }

    private function buildToolUseSseStream(): string
    {
        return $this->buildSseStream([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 0]],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'sort_classification', 'input' => []],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"topic":'],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'input_json_delta', 'partial_json' => '"mediamaker"}'],
            ]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'tool_use'],
                'usage' => ['output_tokens' => 8],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function decodeRequestBody(array $options): array
    {
        if (isset($options['json']) && is_array($options['json'])) {
            return $options['json'];
        }

        $body = $options['body'] ?? '';
        if (is_string($body) && '' !== $body) {
            $decoded = json_decode($body, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function makeProvider(?HttpClientInterface $httpClient = null): AnthropicProvider
    {
        return new AnthropicProvider(
            $httpClient ?? $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            self::API_KEY,
        );
    }
}
