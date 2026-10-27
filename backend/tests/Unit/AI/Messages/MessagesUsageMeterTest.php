<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Messages;

use App\AI\Messages\MessagesUsage;
use PHPUnit\Framework\TestCase;

final class MessagesUsageMeterTest extends TestCase
{
    public function testCacheOnlyUsageIsStillMetered(): void
    {
        $usage = new MessagesUsage(inputTokens: 0, outputTokens: 0, cacheCreationTokens: 0, cacheReadTokens: 8000);

        $this->assertTrue($usage->shouldMeter());
    }

    public function testAFinishedStreamWithNoTokenFieldsIsMetered(): void
    {
        $usage = new MessagesUsage(stopReason: 'end_turn');

        $this->assertTrue($usage->shouldMeter());
    }

    public function testAnEmptyFailureIsNotMetered(): void
    {
        $this->assertFalse((new MessagesUsage())->shouldMeter());
    }
}
