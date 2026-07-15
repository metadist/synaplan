<?php

declare(strict_types=1);

namespace App\Tests\AI\Provider;

use App\AI\Provider\GoogleProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Gemini rejects empty `parts`, empty text parts, non-alternating roles and a
 * non-`user` leading turn with HTTP 400 — the same class of failure that broke
 * WhatsApp turns on Anthropic. These tests lock in that
 * convertMessagesToGeminiFormat() sanitises the thread before the request.
 */
class GoogleProviderMessageNormalizationTest extends TestCase
{
    public function testBlankTurnsAreDroppedAndAlternationPreserved(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return $this->okStream();
        });

        $this->makeProvider($client)->chatStream(
            [
                ['role' => 'user', 'content' => 'q1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['role' => 'assistant', 'content' => '   '], // blank model row → dropped
                ['role' => 'user', 'content' => 'q2'],
            ],
            static fn () => null,
            ['model' => 'gemini-2.5-flash'],
        );

        $this->assertNotNull($captured);
        $contents = $captured['contents'];

        $this->assertSame(['user', 'model', 'user'], array_column($contents, 'role'));
        $this->assertNoEmptyTextParts($contents);
        $this->assertSame('q1', $contents[0]['parts'][0]['text']);
        $this->assertSame('a1', $contents[1]['parts'][0]['text']);
        $this->assertSame('q2', $contents[2]['parts'][0]['text']);
    }

    public function testLeadingModelTurnsAreDropped(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return $this->okStream();
        });

        $this->makeProvider($client)->chatStream(
            [
                ['role' => 'assistant', 'content' => 'leftover reply from trimmed history'],
                ['role' => 'user', 'content' => 'actual question'],
            ],
            static fn () => null,
            ['model' => 'gemini-2.5-flash'],
        );

        $this->assertNotNull($captured);
        $contents = $captured['contents'];
        $this->assertNotEmpty($contents);
        $this->assertSame('user', $contents[0]['role'], 'conversation must start with a user turn');
        $this->assertSame('actual question', $contents[0]['parts'][0]['text']);
    }

    public function testConsecutiveSameRoleTurnsAreMerged(): void
    {
        $captured = null;
        $client = new MockHttpClient(function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $this->decodeRequestBody($options);

            return $this->okStream();
        });

        // system folds to a user turn; the following user turn must merge with it
        // instead of producing two consecutive user turns (a Gemini 400).
        $this->makeProvider($client)->chatStream(
            [
                ['role' => 'system', 'content' => 'You are helpful.'],
                ['role' => 'user', 'content' => 'hello'],
            ],
            static fn () => null,
            ['model' => 'gemini-2.5-flash'],
        );

        $this->assertNotNull($captured);
        $contents = $captured['contents'];
        $this->assertCount(1, $contents);
        $this->assertSame('user', $contents[0]['role']);
        $this->assertSame(['You are helpful.', 'hello'], array_column($contents[0]['parts'], 'text'));
    }

    // ==================== HELPERS ====================

    /**
     * @param list<array{role: string, parts: list<array<string, mixed>>}> $contents
     */
    private function assertNoEmptyTextParts(array $contents): void
    {
        foreach ($contents as $content) {
            foreach ($content['parts'] as $part) {
                if (isset($part['text'])) {
                    $this->assertNotSame('', trim((string) $part['text']), 'Gemini rejects empty text parts');
                }
            }
        }
    }

    /**
     * A minimal, well-formed Gemini SSE stream (single text chunk).
     */
    private function okStream(): MockResponse
    {
        $event = 'data: '.json_encode([
            'candidates' => [[
                'content' => ['parts' => [['text' => 'ok']]],
                'finishReason' => 'STOP',
            ]],
            'usageMetadata' => ['promptTokenCount' => 1, 'candidatesTokenCount' => 1],
        ])."\n\n";

        return new MockResponse($event, ['response_headers' => ['content-type' => 'text/event-stream']]);
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

    private function makeProvider(MockHttpClient $httpClient): GoogleProvider
    {
        return new GoogleProvider(new NullLogger(), $httpClient, 'test-key');
    }
}
