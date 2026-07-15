<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Exception\ProviderException;
use App\AI\Provider\AnthropicProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Regression tests for AnthropicProvider SSE stream parsing.
 *
 * Focus: thinking_delta events must be forwarded as `reasoning` callbacks
 * (issue #1054). text_delta events must still arrive as `content` callbacks.
 */
class AnthropicProviderStreamingTest extends TestCase
{
    private const API_KEY = 'test-key';

    // ==================== THINKING DELTA REGRESSION (issue #1054) ====================

    /**
     * A full Anthropic SSE stream with an extended-thinking model:
     *   message_start
     *   → content_block_start (thinking)
     *   → thinking_delta × 2
     *   → content_block_stop
     *   → content_block_start (text)
     *   → text_delta
     *   → content_block_stop
     *   → message_delta (usage)
     *   → message_stop
     *
     * Before the fix, thinking_delta was silently dropped.
     * After the fix, each thinking_delta must arrive as type=reasoning.
     */
    public function testThinkingDeltaIsForwardedAsReasoningCallback(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 0]],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'thinking', 'thinking' => ''],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => 'Let me think...'],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => ' More reasoning.'],
            ]],
            ['event' => 'content_block_stop', 'data' => [
                'type' => 'content_block_stop',
                'index' => 0,
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 1,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 1,
                'delta' => ['type' => 'text_delta', 'text' => 'The answer is 42.'],
            ]],
            ['event' => 'content_block_stop', 'data' => [
                'type' => 'content_block_stop',
                'index' => 1,
            ]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 25],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $client = new MockHttpClient(static fn () => new MockResponse($sse, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $received = [];
        $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'What is the meaning of life?']],
            static function (mixed $chunk) use (&$received): void {
                $received[] = $chunk;
            },
            ['model' => 'claude-fable-5', 'reasoning' => true],
        );

        $reasoningChunks = array_values(array_filter($received, static fn ($c) => 'reasoning' === ($c['type'] ?? null)));
        $contentChunks = array_values(array_filter($received, static fn ($c) => 'content' === ($c['type'] ?? null)));

        $this->assertCount(2, $reasoningChunks, 'Both thinking_delta events must be forwarded as reasoning');
        $this->assertSame('Let me think...', $reasoningChunks[0]['content']);
        $this->assertSame(' More reasoning.', $reasoningChunks[1]['content']);

        $this->assertCount(1, $contentChunks, 'text_delta must still arrive as content');
        $this->assertSame('The answer is 42.', $contentChunks[0]['content']);
    }

    /**
     * signature_delta events (thinking block integrity verification) must be silently
     * ignored — they must not produce any callback invocation.
     */
    public function testSignatureDeltaIsIgnored(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 5, 'output_tokens' => 0]],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'thinking', 'thinking' => ''],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'thinking_delta', 'thinking' => 'Some thought.'],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'signature_delta', 'signature' => 'abc123verificationtoken'],
            ]],
            ['event' => 'content_block_stop', 'data' => [
                'type' => 'content_block_stop',
                'index' => 0,
            ]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 5],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $client = new MockHttpClient(static fn () => new MockResponse($sse, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $received = [];
        $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'Think about something.']],
            static function (mixed $chunk) use (&$received): void {
                $received[] = $chunk;
            },
            ['model' => 'claude-opus-4-8', 'reasoning' => true],
        );

        $signatureChunks = array_filter($received, static fn ($c) => 'signature' === ($c['type'] ?? null));
        $this->assertCount(0, $signatureChunks, 'signature_delta must not produce a callback');

        $reasoningChunks = array_values(array_filter($received, static fn ($c) => 'reasoning' === ($c['type'] ?? null)));
        $this->assertCount(1, $reasoningChunks);
        $this->assertSame('Some thought.', $reasoningChunks[0]['content']);
    }

    /**
     * A plain (non-thinking) model stream must still work: text_delta -> content.
     */
    public function testTextOnlyStreamStillWorks(): void
    {
        $sse = $this->buildSseStream([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 8, 'output_tokens' => 0]],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'Hello'],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => ' world'],
            ]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 12],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);

        $client = new MockHttpClient(static fn () => new MockResponse($sse, [
            'response_headers' => ['content-type' => 'text/event-stream'],
        ]));

        $received = [];
        $result = $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'Hi']],
            static function (mixed $chunk) use (&$received): void {
                $received[] = $chunk;
            },
            ['model' => 'claude-haiku-4-5'],
        );

        $contentChunks = array_values(array_filter($received, static fn ($c) => 'content' === ($c['type'] ?? null)));
        $this->assertCount(2, $contentChunks);
        $this->assertSame('Hello', $contentChunks[0]['content']);
        $this->assertSame(' world', $contentChunks[1]['content']);

        $reasoningChunks = array_filter($received, static fn ($c) => 'reasoning' === ($c['type'] ?? null));
        $this->assertCount(0, $reasoningChunks, 'No reasoning expected for text-only stream');
        $this->assertSame(20, $result['usage']['total_tokens']);
    }

    // ==================== MESSAGE SANITISATION (WhatsApp 400 fix) ====================

    /**
     * Empty-content turns (e.g. a WhatsApp media-only / placeholder / error row
     * stored with blank text) must be dropped before the request — Anthropic
     * rejects empty content with a 400 while OpenAI-style providers tolerate it.
     * The two surviving user turns collapse into one via role merging.
     */
    public function testEmptyHistoryTurnsAreDroppedBeforeRequest(): void
    {
        $captured = null;
        $sse = $this->buildOkStream();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $sse): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->makeProvider(httpClient: $client)->chatStream(
            [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'first question'],
                ['role' => 'assistant', 'content' => '   '], // blank OUT row
                ['role' => 'user', 'content' => 'second question'],
            ],
            static fn () => null,
            ['model' => 'claude-haiku-4-5'],
        );

        $this->assertNotNull($captured);
        $messages = $captured['messages'];

        foreach ($messages as $message) {
            $this->assertNotSame('', trim((string) $message['content']), 'no empty-content message may be sent to Anthropic');
        }

        $this->assertCount(1, $messages, 'the two user turns merge once the blank assistant row is dropped');
        $this->assertSame('user', $messages[0]['role']);
        $this->assertStringContainsString('first question', $messages[0]['content']);
        $this->assertStringContainsString('second question', $messages[0]['content']);
    }

    /**
     * When the loaded history window begins on an assistant turn (the opening
     * user message was trimmed off), the leading assistant turn must be dropped
     * so the request starts with a `user` message — Anthropic 400s otherwise.
     */
    public function testLeadingAssistantTurnsAreDropped(): void
    {
        $captured = null;
        $sse = $this->buildOkStream();
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured, $sse): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return new MockResponse($sse, ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $this->makeProvider(httpClient: $client)->chatStream(
            [
                ['role' => 'assistant', 'content' => 'leftover reply from trimmed history'],
                ['role' => 'user', 'content' => 'actual question'],
            ],
            static fn () => null,
            ['model' => 'claude-haiku-4-5'],
        );

        $this->assertNotNull($captured);
        $messages = $captured['messages'];
        $this->assertNotEmpty($messages);
        $this->assertSame('user', $messages[0]['role'], 'conversation must start with a user turn');
        $this->assertSame('actual question', $messages[0]['content']);
    }

    /**
     * A streaming HTTP error (buffer:false) must surface Anthropic's real
     * error.message/type — not the opaque "HTTP 400 returned for …" transport
     * message the WhatsApp user previously saw.
     */
    public function testStreamingSurfacesAnthropicErrorDetailOnHttpError(): void
    {
        $errorJson = json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'messages: text content blocks must be non-empty',
            ],
        ]);

        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) $errorJson, ['http_code' => 400]));

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('messages: text content blocks must be non-empty');

        $this->makeProvider(httpClient: $client)->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            static fn () => null,
            ['model' => 'claude-haiku-4-5'],
        );
    }

    // ==================== HELPERS ====================

    /**
     * Build a single SSE string from an array of [event, data] pairs.
     * Each event is separated by a double newline as per the SSE spec.
     *
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

    /**
     * A minimal, well-formed success SSE stream (single text delta) for tests
     * that only care about the outgoing request body.
     */
    private function buildOkStream(): string
    {
        return $this->buildSseStream([
            ['event' => 'message_start', 'data' => [
                'type' => 'message_start',
                'message' => ['usage' => ['input_tokens' => 1, 'output_tokens' => 0]],
            ]],
            ['event' => 'content_block_start', 'data' => [
                'type' => 'content_block_start',
                'index' => 0,
                'content_block' => ['type' => 'text', 'text' => ''],
            ]],
            ['event' => 'content_block_delta', 'data' => [
                'type' => 'content_block_delta',
                'index' => 0,
                'delta' => ['type' => 'text_delta', 'text' => 'ok'],
            ]],
            ['event' => 'content_block_stop', 'data' => ['type' => 'content_block_stop', 'index' => 0]],
            ['event' => 'message_delta', 'data' => [
                'type' => 'message_delta',
                'delta' => ['stop_reason' => 'end_turn'],
                'usage' => ['output_tokens' => 1],
            ]],
            ['event' => 'message_stop', 'data' => ['type' => 'message_stop']],
        ]);
    }

    /**
     * Decode the JSON request body captured from the MockHttpClient callback.
     * Symfony normalises the `json` option into a `body` string before the
     * response factory runs, so read that back.
     *
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
