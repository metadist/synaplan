<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Stream;

use App\AI\Stream\VisibleAnswer;
use PHPUnit\Framework\TestCase;

final class VisibleAnswerTest extends TestCase
{
    public function testStripsClosedAndUnclosedThinkBlocks(): void
    {
        self::assertSame(
            'The page says hello.',
            VisibleAnswer::withoutReasoning("<think>scratch</think>\nThe page says hello."),
        );
        self::assertSame('', VisibleAnswer::withoutReasoning('<think>still going'));
    }

    public function testCutJsonIsUnusable(): void
    {
        $raw = '{"version":1,"tasks":[{"id":"n1","capability":"mcp_fetch"';

        self::assertTrue(VisibleAnswer::isCutJson($raw));
        self::assertTrue(VisibleAnswer::isUnusable($raw, 'length'));
        self::assertTrue(VisibleAnswer::failedBecauseOutputWasCut($raw, 'length'));
        self::assertTrue(VisibleAnswer::failedBecauseOutputWasCut($raw, null));
    }

    public function testProseThatStoppedCleanlyIsUsable(): void
    {
        self::assertFalse(VisibleAnswer::isUnusable('Here is what I found.', 'stop'));
        self::assertFalse(VisibleAnswer::failedBecauseOutputWasCut('Here is what I found.', 'length'));
        self::assertFalse(VisibleAnswer::isCutJson('{"a":1,}'));
    }

    public function testUnclosedThinkCountsAsCutOffEvenWithoutFinishReason(): void
    {
        $raw = '<think>I should call confluence';

        self::assertTrue(VisibleAnswer::reasoningSwallowedTheAnswer($raw));
        self::assertTrue(VisibleAnswer::failedBecauseOutputWasCut($raw, null));
    }

    public function testClosedThinkWithNoAnswerIsEmptyNotCutOff(): void
    {
        $raw = '<think>done</think>';

        self::assertTrue(VisibleAnswer::isUnusable($raw, 'stop'));
        self::assertFalse(VisibleAnswer::failedBecauseOutputWasCut($raw, 'stop'));
    }

    public function testRecognisesLocalThinkingModels(): void
    {
        self::assertTrue(VisibleAnswer::modelHidesAnswerBehindThinking('qwen3.8:27b'));
        self::assertTrue(VisibleAnswer::modelHidesAnswerBehindThinking('QwQ-32B'));
        self::assertFalse(VisibleAnswer::modelHidesAnswerBehindThinking('llama3.1:8b'));
    }
}
