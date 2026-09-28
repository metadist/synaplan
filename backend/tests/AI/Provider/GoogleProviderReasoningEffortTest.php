<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class GoogleProviderReasoningEffortTest extends TestCase
{
    public function testChosenLowKeepsANonZeroBudget(): void
    {
        $captured = $this->captureStream('gemini-2.5-flash', [
            'reasoning' => true,
            'reasoning_effort' => 'low',
        ]);

        self::assertSame(512, $captured['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null);
    }

    public function testReasoningOffStillDisablesFlashThinking(): void
    {
        $captured = $this->captureStream('gemini-2.5-flash', [
            'reasoning' => false,
        ]);

        self::assertSame(0, $captured['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null);
    }

    public function testReasoningOffOmitsTheBudgetOnPro(): void
    {
        $captured = $this->captureStream('gemini-2.5-pro', [
            'reasoning' => false,
        ]);

        self::assertArrayNotHasKey('thinkingConfig', $captured['generationConfig'] ?? []);
    }

    public function testChosenLowOnProIsStillABudget(): void
    {
        $captured = $this->captureStream('gemini-2.5-pro', [
            'reasoning_effort' => 'low',
        ]);

        self::assertSame(512, $captured['generationConfig']['thinkingConfig']['thinkingBudget'] ?? null);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function captureStream(string $model, array $options): array
    {
        $captured = [];
        $sse = "data: {\"candidates\":[{\"content\":{\"parts\":[{\"text\":\"ok\"}]}}]}\n\n";
        $client = new MockHttpClient(function (string $method, string $url, array $requestOptions) use (&$captured, $sse): MockResponse {
            $captured = $this->decodeRequestBody($requestOptions);

            return new MockResponse($sse, [
                'response_headers' => ['content-type' => 'text/event-stream'],
            ]);
        });

        (new GoogleProvider(new NullLogger(), $client, 'test-key'))->chatStream(
            [['role' => 'user', 'content' => 'Hello']],
            static function (): void {},
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
