<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Chat;
use PHPUnit\Framework\TestCase;

final class ChatPinTest extends TestCase
{
    public function testPinningKeepsTheFirstPinTimeAndNeverTheActivity(): void
    {
        $chat = new Chat();
        $lastActivity = $chat->getUpdatedAt();

        $chat->pin(true);
        $firstPin = $chat->getPinnedAt();
        $chat->pin(true);

        self::assertTrue($chat->isPinned());
        self::assertNotNull($firstPin);
        self::assertSame($firstPin, $chat->getPinnedAt(), 'Pinning a pinned chat again keeps its first pin time.');
        self::assertSame($lastActivity, $chat->getUpdatedAt(), 'Pinning is list placement, not activity.');

        $chat->pin(false);

        self::assertSame([false, null], [$chat->isPinned(), $chat->getPinnedAt()]);
        self::assertSame($lastActivity, $chat->getUpdatedAt());
    }
}
