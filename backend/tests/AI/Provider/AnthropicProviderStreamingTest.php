<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

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

    private function makeProvider(?HttpClientInterface $httpClient = null): AnthropicProvider
    {
        return new AnthropicProvider(
            $httpClient ?? $this->createMock(HttpClientInterface::class),
            new NullLogger(),
            self::API_KEY,
        );
    }
}
