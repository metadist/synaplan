<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Widget\Turn;

use App\Service\Widget\Turn\WidgetAnswerChunks;
use PHPUnit\Framework\TestCase;

final class WidgetAnswerChunksTest extends TestCase
{
    public function testPlainChunksStreamAsTheyComeAndMakeTheAnswer(): void
    {
        $chunks = new WidgetAnswerChunks();

        self::assertSame(['Hello'], $chunks->push('Hello'));
        self::assertSame([], $chunks->push(''));
        self::assertSame([' there.'], $chunks->push(' there.'));
        self::assertSame([], $chunks->finish());
        self::assertSame('Hello there.', $chunks->text());
    }

    public function testReasoningIsSentAsOneBlockWhenTheAnswerStarts(): void
    {
        $chunks = new WidgetAnswerChunks();

        self::assertSame([], $chunks->push(['type' => 'reasoning', 'content' => 'Weighing']));
        self::assertSame([], $chunks->push(['type' => 'reasoning', 'content' => ' it.']));
        self::assertSame(['<think>Weighing it.</think>', 'Answer'], $chunks->push(['type' => 'content', 'content' => 'Answer']));
        self::assertSame('<think>Weighing it.</think>Answer', $chunks->text());
    }

    public function testReasoningBeforeAPlainChunkIsClosedFirst(): void
    {
        $chunks = new WidgetAnswerChunks();
        $chunks->push(['type' => 'reasoning', 'content' => 'Hm.']);

        self::assertSame(['<think>Hm.</think>', 'Yes'], $chunks->push('Yes'));
    }

    public function testReasoningLeftOpenIsClosedWhenTheStreamEnds(): void
    {
        $chunks = new WidgetAnswerChunks();
        $chunks->push(['type' => 'reasoning', 'content' => 'Only thinking']);

        self::assertSame(['<think>Only thinking</think>'], $chunks->finish());
        self::assertSame('<think>Only thinking</think>', $chunks->text());
    }

    public function testAJsonAnswerStreamsItsBtextOnceItParses(): void
    {
        $chunks = new WidgetAnswerChunks();

        self::assertSame([], $chunks->push("{\"BTEXT\": \"From JSON\", \"BFILE\": \n"));
        self::assertSame(['From JSON'], $chunks->push('}'));
        self::assertSame([', more'], $chunks->push(', more'));
        self::assertSame('From JSON, more', $chunks->text());
    }

    public function testJsonWithoutBtextIsHeldBack(): void
    {
        $chunks = new WidgetAnswerChunks();

        self::assertSame([], $chunks->push('{"answer": 1}'));
        self::assertSame([], $chunks->push(' trailing'));
        self::assertSame('', $chunks->text());
    }

    public function testOnlyTheFirstChunkCanOpenAJsonAnswer(): void
    {
        $chunks = new WidgetAnswerChunks();
        $chunks->push('Plain ');

        self::assertSame(['{not json}'], $chunks->push('{not json}'));
    }

    public function testTheFinishReasonIsKeptAndSendsNothing(): void
    {
        $chunks = new WidgetAnswerChunks();

        self::assertSame([], $chunks->push(['type' => 'finish', 'finish_reason' => 'length']));
        self::assertSame('length', $chunks->finishReason());
        self::assertSame('', $chunks->text());
    }
}
