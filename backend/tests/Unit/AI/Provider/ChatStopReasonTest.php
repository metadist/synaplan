<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Provider;

use App\AI\Provider\ChatStopReason;
use PHPUnit\Framework\TestCase;

final class ChatStopReasonTest extends TestCase
{
    public function testACutOffAnswerIsReportedAsLength(): void
    {
        $result = ChatStopReason::apply(['content' => 'Only the start.'], [], 'max_tokens');

        self::assertSame('length', $result['finish_reason']);
        self::assertSame('Only the start.', $result['content']);
    }

    public function testGeminiNamesTheSameCutoff(): void
    {
        $result = ChatStopReason::apply(['content' => 'Nur der Anfang.'], [], 'MAX_TOKENS');

        self::assertSame('length', $result['finish_reason']);
    }

    public function testRealToolCallsWinOverACutoff(): void
    {
        $calls = [['id' => 'toolu_1']];

        $result = ChatStopReason::apply(['content' => ''], $calls, 'max_tokens');

        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertSame($calls, $result['tool_calls']);
    }

    public function testAnEmptyToolUseStopIsStillAToolTurn(): void
    {
        $result = ChatStopReason::apply(['content' => ''], [], 'tool_use');

        self::assertSame('tool_calls', $result['finish_reason']);
        self::assertArrayNotHasKey('tool_calls', $result);
    }

    public function testAFinishedAnswerGainsNoFinishReason(): void
    {
        $result = ChatStopReason::apply(['content' => 'Paris.'], [], 'end_turn');

        self::assertArrayNotHasKey('finish_reason', $result);
    }
}
