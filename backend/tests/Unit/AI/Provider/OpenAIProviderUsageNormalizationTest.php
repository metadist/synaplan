<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\OpenAIProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #886 sub-task c — pin OpenAI Responses API usage normalisation.
 *
 * The cost engine reads cache_creation_tokens / cached_tokens off the
 * usage payload returned by every chat call. Previously the Responses
 * API normaliser hard-coded cache_creation_tokens=0 which silently
 * dropped prompt-cache write costs from invoices. This test locks in
 * the corrected mapping against the real OpenAI shape:
 *   usage.input_tokens_details.cache_write_tokens
 *   usage.input_tokens_details.cached_tokens
 *
 * The internal key keeps Anthropic's `cache_creation_tokens` name; only the
 * OpenAI-side key differs.
 */
final class OpenAIProviderUsageNormalizationTest extends TestCase
{
    public function testNormalizeResponsesUsageMapsCacheWriteTokens(): void
    {
        $responseData = [
            'usage' => [
                'input_tokens' => 1234,
                'output_tokens' => 567,
                'total_tokens' => 1801,
                'input_tokens_details' => [
                    'cached_tokens' => 200,
                    'cache_write_tokens' => 100,
                ],
            ],
        ];

        $result = $this->invokeNormalize($responseData);

        self::assertSame(1234, $result['prompt_tokens']);
        self::assertSame(567, $result['completion_tokens']);
        self::assertSame(1801, $result['total_tokens']);
        self::assertSame(200, $result['cached_tokens']);
        self::assertSame(100, $result['cache_creation_tokens']);
    }

    /**
     * Regression guard: the normaliser used to read `cache_creation_tokens`
     * (Anthropic's name) off the OpenAI payload. That key never appears there,
     * so written tokens stayed 0 and were billed as ordinary input at 1.0x
     * instead of the 1.25x cache-write rate. Reading the wrong key again must
     * not silently start "working".
     */
    public function testNormalizeResponsesUsageIgnoresAnthropicCacheKey(): void
    {
        $result = $this->invokeNormalize([
            'usage' => [
                'input_tokens' => 500,
                'input_tokens_details' => [
                    'cache_creation_tokens' => 100,
                ],
            ],
        ]);

        self::assertSame(0, $result['cache_creation_tokens']);
    }

    public function testNormalizeResponsesUsageDefaultsToZeroWhenFieldsMissing(): void
    {
        $result = $this->invokeNormalize(['usage' => []]);

        self::assertSame(0, $result['prompt_tokens']);
        self::assertSame(0, $result['completion_tokens']);
        self::assertSame(0, $result['total_tokens']);
        self::assertSame(0, $result['cached_tokens']);
        self::assertSame(0, $result['cache_creation_tokens']);
    }

    /**
     * @param array<string, mixed> $responseData
     *
     * @return array<string, int>
     */
    private function invokeNormalize(array $responseData): array
    {
        $reflection = new \ReflectionClass(OpenAIProvider::class);
        $method = $reflection->getMethod('normalizeResponsesUsage');
        $method->setAccessible(true);

        // We need a constructed instance to satisfy ReflectionMethod::invoke,
        // but normalizeResponsesUsage doesn't touch any state.
        $instance = $reflection->newInstanceWithoutConstructor();

        return $method->invoke($instance, $responseData);
    }
}
