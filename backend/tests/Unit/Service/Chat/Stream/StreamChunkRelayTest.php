<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Service\Chat\Stream\StreamChunkRelay;
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
}
