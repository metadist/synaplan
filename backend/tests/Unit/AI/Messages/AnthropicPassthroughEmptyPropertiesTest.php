<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\AnthropicJsonSchemaNormalizer;
use App\AI\Messages\Translator\AnthropicPassthroughTranslator;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class AnthropicPassthroughEmptyPropertiesTest extends TestCase
{
    public function testReencodedBodyKeepsEmptyToolPropertiesAsObject(): void
    {
        $captured = null;
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
            $captured = $options['body'] ?? '';

            return new MockResponse(json_encode([
                'id' => 'msg_test',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'ok']],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
            ], \JSON_THROW_ON_ERROR));
        });

        $translator = new AnthropicPassthroughTranslator(
            $client,
            new NullLogger(),
            new AnthropicJsonSchemaNormalizer(),
        );

        // Empty properties array = what json_decode(true) produces for {}.
        $requestBody = [
            'model' => 'claude-sonnet-5',
            'max_tokens' => 16,
            'messages' => [['role' => 'user', 'content' => 'hi']],
            'tools' => [[
                'name' => 'CronList',
                'description' => 'List cron jobs',
                'input_schema' => [
                    'type' => 'object',
                    'properties' => [],
                    'additionalProperties' => false,
                ],
            ]],
        ];

        $translator->complete($requestBody, [
            'api_key' => 'test-key',
            'upstream_url' => 'https://api.anthropic.com',
            'anthropic_version' => '2023-06-01',
            // Force the re-encode path used after tool injection.
            'raw_body' => null,
        ]);

        self::assertIsString($captured);
        self::assertStringContainsString('"properties":{}', $captured);
        self::assertStringNotContainsString('"properties":[]', $captured);
    }
}
