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

    public function testChosenHighEffortIsSentAndEnablesThinking(): void
    {
        $captured = $this->captureChat('claude-sonnet-4-6', [
            'reasoning' => false,
            'reasoning_effort' => 'high',
        ]);

        self::assertSame('high', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(['type' => 'adaptive'], $captured['body']['thinking'] ?? null);
    }

    public function testChosenLowEffortOnOpusOverridesTheMinimum(): void
    {
        $captured = $this->captureChat('claude-opus-5-5', [
            'reasoning' => true,
            'reasoning_effort' => 'low',
        ]);

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
        self::assertArrayNotHasKey('anthropic-beta', $captured['headers']);
    }

    public function testUnknownEffortAboveHighClampsDown(): void
    {
        $captured = $this->captureChat('claude-opus-4-6', [
            'reasoning_effort' => 'max',
        ]);

        self::assertSame('high', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(['type' => 'adaptive'], $captured['body']['thinking'] ?? null);
    }

    public function testBudgetModelsIgnoreAChosenEffort(): void
    {
        $captured = $this->captureChat('claude-haiku-4-5', [
            'reasoning' => true,
            'reasoning_effort' => 'high',
        ]);

        self::assertArrayNotHasKey('output_config', $captured['body']);
    }

    public function testReasoningOffDisablesThinkingForHaiku55(): void
    {
        $captured = $this->captureChat('claude-haiku-5-5', ['temperature' => 0.2]);

        // Omitting `thinking` would run adaptive thinking on this model.
        self::assertSame(['type' => 'disabled'], $captured['body']['thinking'] ?? null);
        self::assertArrayNotHasKey('output_config', $captured['body']);
        self::assertArrayNotHasKey('temperature', $captured['body']);
        self::assertArrayNotHasKey('anthropic-beta', $captured['headers']);
    }

    public function testStreamingReasoningOffDisablesThinkingForHaiku55(): void
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
            ['model' => 'claude-haiku-5-5'],
        );

        self::assertSame(['type' => 'disabled'], $captured['body']['thinking'] ?? null);
    }

    public function testReasoningOnUsesAdaptiveThinkingForHaiku55(): void
    {
        $captured = $this->captureChat('claude-haiku-5-5', ['reasoning' => true]);

        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
    }

    public function testChosenMaxEffortOnHaiku55KeepsMaxWithAdaptiveThinking(): void
    {
        $captured = $this->captureChat('claude-haiku-5-5', [
            'reasoning' => false,
            'reasoning_effort' => 'max',
            'temperature' => 0.2,
        ]);

        // Haiku 5.5 accepts max, but `disabled` next to xhigh or max is a 400,
        // so the chosen level keeps adaptive thinking.
        self::assertSame('max', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame('adaptive', $captured['body']['thinking']['type'] ?? null);
        self::assertArrayNotHasKey('temperature', $captured['body']);
    }

    public function testChosenXhighEffortOnHaiku55WithReasoningOnStaysXhigh(): void
    {
        $captured = $this->captureChat('claude-haiku-5-5', [
            'reasoning' => true,
            'reasoning_effort' => 'xhigh',
        ]);

        self::assertSame('xhigh', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
    }

    public function testVisionDisablesThinkingForHaiku55(): void
    {
        $captured = $this->captureVision('claude-haiku-5-5');

        // The vision call has a small max_tokens that thinking would use up.
        self::assertSame(['type' => 'disabled'], $captured['body']['thinking'] ?? null);
    }

    public function testVisionLeavesThinkingUnsetForSonnet5(): void
    {
        $captured = $this->captureVision('claude-sonnet-5');

        self::assertArrayNotHasKey('thinking', $captured['body']);
    }

    public function testChosenLowEffortOnHaiku55KeepsThinkingOffWhenReasoningIsOff(): void
    {
        $captured = $this->captureChat('claude-haiku-5-5', [
            'reasoning' => false,
            'reasoning_effort' => 'low',
        ]);

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(['type' => 'disabled'], $captured['body']['thinking'] ?? null);
    }

    public function testReasoningOffSendsBetweenToolsForSonnet55(): void
    {
        $captured = $this->captureChat('claude-sonnet-5-5', []);

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(['type' => 'between_tools'], $captured['body']['thinking'] ?? null);
        self::assertArrayNotHasKey('anthropic-beta', $captured['headers']);
    }

    public function testReasoningOffSendsBetweenToolsForDatedSonnet55(): void
    {
        $captured = $this->captureChat('claude-sonnet-5-5-20260928', []);

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(['type' => 'between_tools'], $captured['body']['thinking'] ?? null);
        self::assertArrayNotHasKey('display', $captured['body']['thinking']);
    }

    public function testChosenHighEffortOnSonnet55StaysBetweenToolsWhenReasoningIsOff(): void
    {
        $captured = $this->captureChat('claude-sonnet-5-5', [
            'reasoning' => false,
            'reasoning_effort' => 'high',
        ]);

        self::assertSame('high', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(['type' => 'between_tools'], $captured['body']['thinking'] ?? null);
    }

    public function testMaxEffortOnSonnet55KeepsAdaptiveThinking(): void
    {
        $captured = $this->captureChat('claude-sonnet-5-5', [
            'reasoning' => false,
            'reasoning_effort' => 'max',
        ]);

        self::assertSame('max', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
    }

    public function testReasoningOnSonnet55SendsAdaptiveThinkingAtChosenEffort(): void
    {
        $captured = $this->captureChat('claude-sonnet-5-5', [
            'reasoning' => true,
            'reasoning_effort' => 'xhigh',
        ]);

        self::assertSame('xhigh', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
    }

    public function testSonnet5ReasoningOffDoesNotUseBetweenTools(): void
    {
        $captured = $this->captureChat('claude-sonnet-5', []);

        self::assertArrayNotHasKey('thinking', $captured['body']);
        self::assertArrayNotHasKey('output_config', $captured['body']);
    }

    public function testReasoningOffSendsLowEffortForFable52(): void
    {
        $captured = $this->captureChat('claude-fable-5-2', ['temperature' => 0.2]);

        self::assertArrayNotHasKey('temperature', $captured['body']);
        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'updates'],
            $captured['body']['thinking'] ?? null,
        );
        self::assertSame('thinking-display-updates-2026-08-18', $captured['headers']['anthropic-beta'] ?? null);
    }

    public function testReasoningOffSendsLowEffortForDatedFable52(): void
    {
        $captured = $this->captureChat('claude-fable-5-2-20261109', []);

        self::assertSame('low', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame('updates', $captured['body']['thinking']['display'] ?? null);
    }

    public function testMaxEffortOnFable52StaysAdaptive(): void
    {
        $captured = $this->captureChat('claude-fable-5-2', [
            'reasoning' => true,
            'reasoning_effort' => 'max',
        ]);

        self::assertSame('max', $captured['body']['output_config']['effort'] ?? null);
        self::assertSame(
            ['type' => 'adaptive', 'display' => 'summarized'],
            $captured['body']['thinking'] ?? null,
        );
        self::assertArrayNotHasKey('temperature', $captured['body']);
    }

    public function testFable51ReasoningOffDoesNotInheritFable52Minimum(): void
    {
        $captured = $this->captureChat('claude-fable-5-1', []);

        self::assertArrayNotHasKey('output_config', $captured['body']);
        self::assertArrayNotHasKey('thinking', $captured['body']);
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
     * @return array{body: array<string, mixed>, headers: array<string, mixed>}
     */
    private function captureVision(string $model): array
    {
        $uploadDir = sys_get_temp_dir().'/anthropic-vision-'.bin2hex(random_bytes(4));
        mkdir($uploadDir);
        // 1x1 transparent PNG.
        file_put_contents($uploadDir.'/pixel.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true));

        $captured = ['body' => [], 'headers' => []];
        $client = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$captured): MockResponse {
            $captured = $this->captureRequest($requestOptions);

            return new MockResponse('{"content":[{"type":"text","text":"a pixel"}],"stop_reason":"end_turn","usage":{"input_tokens":1,"output_tokens":1}}', [
                'response_headers' => ['content-type' => 'application/json'],
            ]);
        });

        try {
            (new AnthropicProvider($client, new NullLogger(), 'test-key', uploadDir: $uploadDir))
                ->analyzeImage('pixel.png', 'Describe it.', ['model' => $model]);
        } finally {
            unlink($uploadDir.'/pixel.png');
            rmdir($uploadDir);
        }

        return $captured;
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
     * @param array<int|string, mixed> $headers
     *
     * @return array<string, string>
     */
    private function flattenHeaders(array $headers): array
    {
        $flat = [];
        foreach ($headers as $name => $value) {
            if (\is_int($name)) {
                if (!\is_string($value) || !str_contains($value, ':')) {
                    continue;
                }
                [$name, $value] = explode(':', $value, 2);
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
