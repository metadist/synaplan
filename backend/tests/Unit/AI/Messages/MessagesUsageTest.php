<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\MessagesUsage;
use PHPUnit\Framework\TestCase;

final class MessagesUsageTest extends TestCase
{
    public function testToRateLimitUsageMapsAnthropicCacheFields(): void
    {
        $usage = new MessagesUsage(
            inputTokens: 100,
            outputTokens: 50,
            cacheCreationTokens: 20,
            cacheReadTokens: 80,
            stopReason: 'end_turn',
        );

        $mapped = $usage->toRateLimitUsage();

        $this->assertSame(200, $mapped['prompt_tokens']); // 100+20+80
        $this->assertSame(50, $mapped['completion_tokens']);
        $this->assertSame(250, $mapped['total_tokens']);
        $this->assertSame(80, $mapped['cached_tokens']);
        $this->assertSame(20, $mapped['cache_creation_tokens']);
        $this->assertSame(0, $mapped['cache_creation_1h_tokens']);
    }

    public function testToRateLimitUsageIncludesOneHourCacheBreakdown(): void
    {
        $usage = new MessagesUsage(
            inputTokens: 100,
            outputTokens: 50,
            cacheCreationTokens: 20,
            cacheReadTokens: 80,
            stopReason: 'end_turn',
            cacheCreation1hTokens: 12,
        );

        $mapped = $usage->toRateLimitUsage();

        $this->assertSame(20, $mapped['cache_creation_tokens']);
        $this->assertSame(12, $mapped['cache_creation_1h_tokens']);
    }

    public function testFromAnthropicUsage(): void
    {
        $usage = MessagesUsage::fromAnthropicUsage([
            'input_tokens' => 10,
            'output_tokens' => 5,
            'cache_creation_input_tokens' => 2,
            'cache_read_input_tokens' => 3,
        ], 'tool_use');

        $this->assertSame(10, $usage->inputTokens);
        $this->assertSame(5, $usage->outputTokens);
        $this->assertSame(2, $usage->cacheCreationTokens);
        $this->assertSame(0, $usage->cacheCreation1hTokens);
        $this->assertSame(3, $usage->cacheReadTokens);
        $this->assertSame('tool_use', $usage->stopReason);
    }

    /**
     * Real Anthropic response shape when a 1-hour-TTL cache breakpoint is used:
     * `cache_creation_input_tokens` is the aggregate (both TTLs), and the nested
     * `cache_creation` object breaks it down by TTL. See
     * https://platform.claude.com/docs/en/build-with-claude/prompt-caching.
     */
    public function testFromAnthropicUsageExtractsOneHourCacheBreakdown(): void
    {
        $usage = MessagesUsage::fromAnthropicUsage([
            'input_tokens' => 2048,
            'output_tokens' => 503,
            'cache_creation_input_tokens' => 248,
            'cache_read_input_tokens' => 1800,
            'cache_creation' => [
                'ephemeral_5m_input_tokens' => 148,
                'ephemeral_1h_input_tokens' => 100,
            ],
        ]);

        $this->assertSame(248, $usage->cacheCreationTokens);
        $this->assertSame(100, $usage->cacheCreation1hTokens);
    }

    public function testExtractCacheCreation1hTokensReturnsZeroWhenBreakdownMissing(): void
    {
        $this->assertSame(0, MessagesUsage::extractCacheCreation1hTokens([
            'cache_creation_input_tokens' => 50,
        ]));
    }

    public function testExtractCacheCreation1hTokensReturnsZeroWhenBreakdownNotAnArray(): void
    {
        // @phpstan-ignore-next-line — defensive against a malformed upstream payload
        $this->assertSame(0, MessagesUsage::extractCacheCreation1hTokens([
            'cache_creation' => 'not-an-array',
        ]));
    }

    public function testWithStopReasonPreservesOneHourCacheBreakdown(): void
    {
        $usage = new MessagesUsage(
            inputTokens: 10,
            outputTokens: 5,
            cacheCreationTokens: 20,
            cacheReadTokens: 3,
            cacheCreation1hTokens: 15,
        );

        $updated = $usage->withStopReason('end_turn');

        $this->assertSame(15, $updated->cacheCreation1hTokens);
        $this->assertSame('end_turn', $updated->stopReason);
    }
}
