<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\PastedContentText;
use PHPUnit\Framework\TestCase;

final class PastedContentTextTest extends TestCase
{
    public function testStripRemovesWrappedBlocksAndKeepsTypedText(): void
    {
        $raw = "<pasted-content>\nstack dump\n</pasted-content>\n\nWhat does this mean?";

        self::assertSame('What does this mean?', PastedContentText::strip($raw));
    }

    public function testStripReturnsEmptyWhenOnlyPastedContentIsPresent(): void
    {
        $raw = "<pasted-content>\nonly paste\n</pasted-content>";

        self::assertSame('', PastedContentText::strip($raw));
    }

    public function testStripLeavesUnmarkedTextUnchanged(): void
    {
        self::assertSame('plain message', PastedContentText::strip('plain message'));
    }
}
