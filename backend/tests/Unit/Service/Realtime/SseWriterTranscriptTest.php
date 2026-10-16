<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Realtime;

use App\Service\Chat\Run\ChatRunRecorder;
use App\Service\Realtime\SseWriter;
use PHPUnit\Framework\TestCase;

final class SseWriterTranscriptTest extends TestCase
{
    public function testLiveFrameKeepsTheTranscriptAndTheRecorderDoesNot(): void
    {
        $recorded = null;
        $recorder = $this->createMock(ChatRunRecorder::class);
        $recorder->expects($this->once())->method('record')->willReturnCallback(
            function (array $event) use (&$recorded): void {
                $recorded = $event;
            }
        );

        $writer = new SseWriter(captureMode: true);
        $writer->attachRun($recorder);

        ob_start();
        $writer->send('complete', [
            'messageId' => 7,
            'providerMessages' => ['messages' => [['role' => 'you', 'text' => 'Hello']], 'truncated' => false],
        ]);
        $frame = (string) ob_get_clean();

        self::assertIsArray($recorded);
        self::assertArrayNotHasKey('providerMessages', $recorded);
        self::assertStringContainsString('providerMessages', $frame);
        self::assertStringContainsString('Hello', $frame);
    }
}
