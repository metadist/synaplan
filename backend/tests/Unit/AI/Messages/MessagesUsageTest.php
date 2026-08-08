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
        $this->assertSame(3, $usage->cacheReadTokens);
        $this->assertSame('tool_use', $usage->stopReason);
    }
}
