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

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        // Thinking still happens. Ask only for the short line between tool calls,
        // not the full summary the person turned off (#2167).
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'updates'],
            $captured['body']['thinking'] ?? null,
        );
        self::assertSame('thinking-display-updates-2026-08-18', $captured['headers']['anthropic-beta'] ?? null);
    }

    public function testReasoningOffSendsLowEffortForDatedOpus55(): void
    {
        $captured = $this->captureChat('claude-opus-5-5-20260922', []);

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame('updates', $captured['body']['thinking']['display'] ?? null);
    }

    public function testReasoningOnDoesNotForceLowEffort(): void
    {
        $captured = $this->captureChat('claude-opus-5-5', ['reasoning' => true]);

        self::assertArrayNotHasKey('output_config', $captured['body']);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
        self::assertArrayNotHasKey('anthropic-beta', $captured['headers']);
    }

    public function testStreamingReasoningOffSendsLowEffortForOpus55(): void
    {
        $captured = [];
        $sse = "event: message_stop\ndata: {\"type\":\"message_stop\"}\n\n";
        $client = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$captured, $sse): MockResponse {
            $captured = $this->captureRequest($requestOptions);

            return new MockResponse($sse, [
                'response_headers' => ['content-type' => 'text/event-stream'],
            ]);
        });

        (new AnthropicProvider($client, new NullLogger(), 'test-key'))->chatStream(
            [['role' => 'user', 'content' => 'Hello']],
            static function (): void {},
            ['model' => 'claude-opus-5-5'],
        );

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame('updates', $captured['body']['thinking']['display'] ?? null);
        self::assertSame('thinking-display-updates-2026-08-18', $captured['headers']['anthropic-beta'] ?? null);
    }

    public function testReasoningOffLeavesOtherModelsUntouched(): void
    {
        $captured = $this->captureChat('claude-sonnet-4-6', []);

        self::assertArrayNotHasKey('output_config', $captured['body']);
        self::assertArrayNotHasKey('thinking', $captured['body']);
    }

    public function testReasoningOnKeepsSummarizedDefaultForSonnet46(): void
    {
        $captured = $this->captureChat('claude-sonnet-4-6', ['reasoning' => true]);

        self::assertSame(['type' => 'adaptive'], $captured['body']['thinking'] ?? null);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{body: array<string, mixed>, headers: array<string, mixed>}
     */
    private function captureChat(string $model, array $options): array
    {
        $captured = ['body' => [], 'headers' => []];
        $client = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$captured): MockResponse {
            $captured = $this->captureRequest($requestOptions);

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
     * @return array{body: array<string, mixed>, headers: array<string, mixed>}
     */
    private function captureRequest(array $options): array
    {
        $headers = $options['headers'] ?? $options['normalized_headers'] ?? [];
        if (!\is_array($headers)) {
            $headers = [];
        }

        return [
            'body' => $this->decodeRequestBody($options),
            'headers' => $this->flattenHeaders($headers),
        ];
    }

    /**
     * @param array<string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            if (\is_int($name) && \is_string($value) && str_contains($value, ':')) {
                [$name, $value] = explode(':', $value, 2);
            }
            if (!\is_string($name)) {
                continue;
            }
            $flat[strtolower(trim($name))] = trim(\is_array($value) ? implode(', ', $value) : (string) $value);
        }

        return $flat;
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
