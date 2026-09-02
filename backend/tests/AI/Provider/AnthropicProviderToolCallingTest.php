<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\AnthropicProvider;
use App\AI\ToolCalling\ToolCall;
use App\AI\ToolCalling\ToolDefinition;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Native tool calling on Anthropic (Phase 9), which shares its wire shape
 * with the structured-output tool forcing tested in
 * {@see AnthropicProviderStructuredOutputTest} but must be read back the
 * OPPOSITE way: there, the single forced `tool_use` block IS the answer and
 * gets folded into `content`; here it is a routing hand-off that must reach
 * the caller as a {@see ToolCall} and never appear in the answer text.
 */
class AnthropicProviderToolCallingTest extends TestCase
{
    private function tool(): ToolDefinition
    {
        return new ToolDefinition('handoff_mediamaker', 'Generate media.', ['type' => 'object', 'properties' => []]);
    }

    public function testChatRequestDeclaresToolsWithAnAutoToolChoice(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = json_decode((string) ($options['body'] ?? '{}'), true);

            return new MockResponse((string) json_encode([
                'content' => [['type' => 'text', 'text' => 'Hi']],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ]));
        });

        $this->makeProvider($client)->chat(
            [['role' => 'user', 'content' => 'Hi']],
            ['model' => 'claude-haiku-4-5', 'tools' => [$this->tool()]],
        );

        $this->assertSame('handoff_mediamaker', $captured['tools'][0]['name']);
        $this->assertArrayHasKey('input_schema', $captured['tools'][0]);
        $this->assertSame(['type' => 'auto'], $captured['tool_choice']);
    }

    public function testToolUseBlocksComeBackAsToolCallsAndStayOutOfTheAnswerText(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'content' => [
                ['type' => 'text', 'text' => 'Sure, generating that.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'handoff_mediamaker', 'input' => ['media_type' => 'image']],
            ],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $result = $this->makeProvider($client)->chat(
            [['role' => 'user', 'content' => 'Make an image of a cat']],
            ['model' => 'claude-haiku-4-5', 'tools' => [$this->tool()]],
        );

        $this->assertSame('Sure, generating that.', $result['content']);
        $this->assertStringNotContainsString('media_type', $result['content']);

        $calls = $result['tool_calls'];
        $this->assertCount(1, $calls);
        $this->assertInstanceOf(ToolCall::class, $calls[0]);
        $this->assertSame('handoff_mediamaker', $calls[0]->name);
        $this->assertSame(['media_type' => 'image'], $calls[0]->arguments);
    }

    public function testAnOrdinaryAnswerReportsNoToolCalls(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse((string) json_encode([
            'content' => [['type' => 'text', 'text' => 'Paris.']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])));

        $result = $this->makeProvider($client)->chat(
            [['role' => 'user', 'content' => 'Capital of France?']],
            ['model' => 'claude-haiku-4-5', 'tools' => [$this->tool()]],
        );

        $this->assertSame('Paris.', $result['content']);
        $this->assertSame([], $result['tool_calls']);
    }

    /**
     * The streaming counterpart of the "stays out of the answer text" rule:
     * with a schema, `input_json_delta` fragments ARE the answer and stream
     * as content; with real tools they are routing plumbing and must not
     * reach the user's screen.
     */
    public function testStreamedToolCallIsAccumulatedInsteadOfStreamedAsContent(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse(
            self::toolUseSseStream(),
            ['response_headers' => ['content-type' => 'text/event-stream']],
        ));

        $received = [];
        $result = $this->makeProvider($client)->chatStream(
            [['role' => 'user', 'content' => 'Make an image of a cat']],
            static function (mixed $chunk) use (&$received): void {
                $received[] = $chunk;
            },
            ['model' => 'claude-haiku-4-5', 'tools' => [$this->tool()]],
        );

        $contentChunks = array_filter($received, static fn ($c) => is_array($c) && 'content' === ($c['type'] ?? null));
        $this->assertSame([], $contentChunks);

        $calls = $result['tool_calls'];
        $this->assertCount(1, $calls);
        $this->assertSame('handoff_mediamaker', $calls[0]->name);
        $this->assertSame(['media_type' => 'image'], $calls[0]->arguments);
    }

    private static function toolUseSseStream(): string
    {
        $events = [
            ['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 10, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'handoff_mediamaker', 'input' => []]],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"media_type":']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"image"}']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use'], 'usage' => ['output_tokens' => 8]],
            ['type' => 'message_stop'],
        ];

        $parts = array_map(
            static fn (array $e): string => 'event: '.$e['type']."\ndata: ".json_encode($e),
            $events,
        );

        return implode("\n\n", $parts)."\n\n";
    }

    private function makeProvider(HttpClientInterface $httpClient): AnthropicProvider
    {
        return new AnthropicProvider($httpClient, new NullLogger(), 'test-key');
    }
}
