<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Service\Chat\Stream\StreamChunkRelay;
use App\Service\Chat\Stream\StreamCompletion;
use App\Service\Media\MediaCancellationStore;
use App\Service\Realtime\SseWriter;
use PHPUnit\Framework\TestCase;

final class StreamChunkRelayTest extends TestCase
{
    private int $now = 0;

    private function relay(): StreamChunkRelay
    {
        return new StreamChunkRelay(
            $this->createStub(SseWriter::class),
            $this->createStub(MediaCancellationStore::class),
            '',
            fn (): int => $this->now,
        );
    }

    public function testReasoningUntilTheFirstAnswerIsRoundedToSeconds(): void
    {
        $relay = $this->relay();
        $relay->onChunk(['type' => 'reasoning', 'content' => 'hmm']);
        $this->now += 4_600_000_000;
        $relay->onChunk(['type' => 'content', 'content' => 'Hi']);

        self::assertSame(5, $relay->reasoningSeconds());
    }

    public function testNoReasoningStoresNoDuration(): void
    {
        $relay = $this->relay();
        $relay->onChunk(['type' => 'content', 'content' => 'Hi']);
        $relay->finish();

        self::assertNull($relay->reasoningSeconds());
    }

    public function testReasoningClosedByFinishIsMeasuredToFinish(): void
    {
        $relay = $this->relay();
        $relay->onChunk(['type' => 'reasoning', 'content' => 'hmm']);
        $this->now += 2_400_000_000;
        $relay->finish();

        self::assertSame(2, $relay->reasoningSeconds());
    }

    public function testInlineThinkTagsAreMeasuredEvenWhenSplitAcrossChunks(): void
    {
        $relay = $this->relay();
        $relay->onChunk('<thi');
        $relay->onChunk('nk>hmm');
        $this->now += 4_600_000_000;
        $relay->onChunk('</think>Answer');

        self::assertSame(5, $relay->reasoningSeconds());
    }

    public function testReasoningThatStartsAfterTheAnswerIsNotMeasured(): void
    {
        $relay = $this->relay();
        $relay->onChunk(['type' => 'content', 'content' => 'Already answering']);
        $this->now += 1_000_000_000;
        $relay->onChunk(['type' => 'reasoning', 'content' => 'too late']);
        $this->now += 4_000_000_000;
        $relay->finish();

        self::assertNull($relay->reasoningSeconds());
    }

    public function testAnEmptyDeltaOrAToolCallDoesNotStopTheClock(): void
    {
        $relay = $this->relay();
        $relay->onChunk(['type' => 'reasoning', 'content' => 'hmm']);
        $this->now += 1_000_000_000;
        $relay->onChunk(['type' => 'content', 'content' => '']);
        $relay->onChunk(['type' => 'tool_call_delta', 'content' => '{"name":"search"}']);
        $this->now += 4_000_000_000;
        $relay->onChunk(['type' => 'content', 'content' => 'Answer']);

        self::assertSame(5, $relay->reasoningSeconds());
    }

    public function testAContinuationKeepsTheDurationAlreadyStored(): void
    {
        self::assertSame(7, StreamCompletion::measuredDuration(7, false));
        self::assertNull(StreamCompletion::measuredDuration(2, true));
        self::assertNull(StreamCompletion::measuredDuration(null, false));
    }
}
