<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\AnthropicProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnthropicProviderReasoningEffortTest extends TestCase
{
    public function testReasoningOffSendsLowEffortForOpus55(): void
    {
        $captured = $this->captureChat('claude-opus-5-5', []);

        self::assertSame('low', $captured['output_config']['effort'] ?? null);
        self::assertArrayNotHasKey('thinking', $captured);
    }

    public function testReasoningOffSendsLowEffortForDatedOpus55(): void
    {
        $captured = $this->captureChat('claude-opus-5-5-20260922', []);

        self::assertSame('low', $captured['output_config']['effort'] ?? null);
    }

    public function testReasoningOnDoesNotForceLowEffort(): void
    {
        $captured = $this->captureChat('claude-opus-5-5', ['reasoning' => true]);

        self::assertArrayNotHasKey('output_config', $captured);
        self::assertSame(['type' => 'adaptive'], $captured['thinking'] ?? null);
    }

    public function testStreamingReasoningOffSendsLowEffortForOpus55(): void
    {
        $captured = [];
        $sse = "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
        $client = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$captured, $sse): MockResponse {
            $captured = $this->decodeRequestBody($requestOptions);

            return new MockResponse($sse, [
                'response_headers' => ['content-type' => 'text/event-stream'],
            ]);
        });

        (new AnthropicProvider($client, new NullLogger(), 'test-key'))->chatStream(
            [['role' => 'user', 'content' => 'Hello']],
            static function (): void {},
            ['model' => 'claude-opus-5-5'],
        );

        self::assertSame('low', $captured['output_config']['effort'] ?? null);
        self::assertArrayNotHasKey('thinking', $captured);
    }

    public function testReasoningOffLeavesOtherModelsUntouched(): void
    {
        $captured = $this->captureChat('claude-sonnet-4-6', []);

        self::assertArrayNotHasKey('output_config', $captured);
        self::assertArrayNotHasKey('thinking', $captured);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function captureChat(string $model, array $options): array
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$captured): MockResponse {
            $captured = $this->decodeRequestBody($requestOptions);

            return new MockResponse('{"content":[{"type":"text","text":"ok"}],"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}', [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        (new AnthropicProvider($client, new NullLogger(), 'test-key'))->chat(
            [['role' => 'user', 'content' => 'Hello']],
            ['model' => $model, ...$options],
        );

        return $captured;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function decodeRequestBody(array $options): array
    {
        if (isset($options['json']) && \is_array($options['json'])) {
            return $options['json'];
        }

        $body = $options['body'] ?? '';
        if (\is_string($body) && '' !== $body) {
            $decoded = json_decode($body, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return [];
    }
}
