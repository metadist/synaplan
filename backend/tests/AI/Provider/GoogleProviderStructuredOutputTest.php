<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\GoogleProvider;
use App\AI\StructuredOutput\StructuredOutputSchema;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Unit tests for GoogleProvider structured-output request shaping (Phase 2b).
 *
 * The translator returns `{'generationConfig': {responseMimeType,
 * responseSchema}}`, which must be merged INTO the already-built
 * `generationConfig` (temperature, topP, …) rather than replacing it — these
 * tests guard that merge on both the non-streaming and streaming request
 * paths.
 */
final class GoogleProviderStructuredOutputTest extends TestCase
{
    public function testChatMergesResponseSchemaIntoExistingGenerationConfig(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => '{}']]]]],
            ]));
        });

        $provider = new GoogleProvider(new NullLogger(), $client, 'fake-api-key');
        $provider->chat([['role' => 'user', 'content' => 'hi']], [
            'model' => 'gemini-2.5-flash',
            'temperature' => 0.3,
            'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
        ]);

        $this->assertSame('application/json', $captured['generationConfig']['responseMimeType']);
        $this->assertSame(['type' => 'object'], $captured['generationConfig']['responseSchema']);
        // The pre-existing generation parameters must survive the merge.
        $this->assertSame(0.3, $captured['generationConfig']['temperature']);
    }

    public function testChatWithoutStructuredOutputOmitsResponseSchema(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse(json_encode([
                'candidates' => [['content' => ['parts' => [['text' => 'hi']]]]],
            ]));
        });

        $provider = new GoogleProvider(new NullLogger(), $client, 'fake-api-key');
        $provider->chat([['role' => 'user', 'content' => 'hi']], ['model' => 'gemini-2.5-flash']);

        $this->assertArrayNotHasKey('responseMimeType', $captured['generationConfig']);
        $this->assertArrayNotHasKey('responseSchema', $captured['generationConfig']);
    }

    public function testChatStreamMergesResponseSchemaIntoExistingGenerationConfig(): void
    {
        $captured = [];
        $client = new MockHttpClient(function (string $method, string $url, array $opts) use (&$captured): MockResponse {
            $captured = json_decode($opts['body'], true);

            return new MockResponse('', ['response_headers' => ['content-type' => 'text/event-stream']]);
        });

        $provider = new GoogleProvider(new NullLogger(), $client, 'fake-api-key');
        $provider->chatStream(
            [['role' => 'user', 'content' => 'hi']],
            static fn () => null,
            [
                'model' => 'gemini-2.5-flash',
                'structured_output' => new StructuredOutputSchema('sort_result', ['type' => 'object']),
            ],
        );

        $this->assertSame('application/json', $captured['generationConfig']['responseMimeType']);
        $this->assertSame(['type' => 'object'], $captured['generationConfig']['responseSchema']);
        // The generation config built above (topP, topK, …) must survive.
        $this->assertArrayHasKey('topP', $captured['generationConfig']);
    }
}
