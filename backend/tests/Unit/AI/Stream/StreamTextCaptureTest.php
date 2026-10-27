<?php

declare(strict_types=1);

namespace App\Tests\Unit\AI\Stream;

use App\AI\Stream\StreamTextCapture;
use PHPUnit\Framework\TestCase;

final class StreamTextCaptureTest extends TestCase
{
    public function testPlainTokensAreTheAnswer(): void
    {
        $capture = StreamTextCapture::wrap(static function (): void {});
        $capture('Hel');
        $capture("lo\nthere");

        $this->assertSame("Hello\nthere", $capture->text());
    }

    public function testReasoningIsNotPartOfTheAnswer(): void
    {
        $capture = StreamTextCapture::wrap(static function (): void {});
        $capture(['type' => 'reasoning', 'content' => 'secret']);
        $capture(['type' => 'content', 'content' => 'Hi']);

        $this->assertSame('Hi', $capture->text());
    }

    public function testMessagesDeltasAndSplitSseLinesAreJoined(): void
    {
        $seen = 0;
        $capture = StreamTextCapture::wrap(static function () use (&$seen): void {
            ++$seen;
        });
        $capture([
            'event' => 'content_block_delta',
            'data' => ['type' => 'content_block_delta', 'delta' => ['type' => 'text_delta', 'text' => 'Yo']],
        ]);
        $capture("event: content_block_delta\n");
        $capture('data: {"type":"content_block_delta","delta":{"type":"text_delta","text":"Hel');
        $capture("lo\"}}\n");

        $this->assertSame('YoHello', $capture->text());
        $this->assertSame(4, $seen);
    }
}
