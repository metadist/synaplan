<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Vision;

use App\AI\Vision\VisionReply;
use PHPUnit\Framework\TestCase;

final class VisionReplyTest extends TestCase
{
    public function testReadsAChatCompletionUsageBlock(): void
    {
        $reply = VisionReply::fromChatCompletion('A cat.', ['prompt_tokens' => 800, 'completion_tokens' => 12, 'total_tokens' => 812, 'prompt_tokens_details' => ['cached_tokens' => 0]]);

        self::assertSame('A cat.', $reply->content);
        self::assertSame(['prompt_tokens' => 800, 'completion_tokens' => 12, 'total_tokens' => 812], $reply->usage);
    }

    public function testReadsAnAnthropicUsageBlockAndAddsTheTotal(): void
    {
        $reply = VisionReply::fromAnthropic('A cat.', ['input_tokens' => 1500, 'output_tokens' => 40]);

        self::assertSame(['prompt_tokens' => 1500, 'completion_tokens' => 40, 'total_tokens' => 1540], $reply->usage);
    }

    public function testAMissingOrEmptyUsageBlockLeavesNoTokens(): void
    {
        self::assertSame([], VisionReply::fromChatCompletion('A cat.', null)->usage);
        self::assertSame([], VisionReply::fromChatCompletion('A cat.', ['prompt_tokens' => 0, 'completion_tokens' => 0])->usage);
        self::assertSame([], VisionReply::fromAnthropic('A cat.', 'not an array')->usage);
    }
}
