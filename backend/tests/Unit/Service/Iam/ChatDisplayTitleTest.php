<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Iam;

use App\Entity\Chat;
use App\Entity\Message;
use App\Service\Iam\ResourceKind\ChatDisplayTitle;
use PHPUnit\Framework\TestCase;

final class ChatDisplayTitleTest extends TestCase
{
    public function testEmptyTitleUsesTheFirstMessage(): void
    {
        $chat = new Chat();
        $chat->setTitle('');
        $chat->getMessages()->add($this->message('IN', 'How do I reset my password for the office?'));

        self::assertSame('How do I reset my password for…', ChatDisplayTitle::of($chat));
    }

    public function testPlaceholderTitleUsesTheFirstMessage(): void
    {
        $chat = new Chat();
        $chat->setTitle('New Chat');
        $chat->getMessages()->add($this->message('OUT', 'Hello'));
        $chat->getMessages()->add($this->message('IN', 'Quarterly plan'));

        self::assertSame('Quarterly plan', ChatDisplayTitle::of($chat));
    }

    public function testRealTitleWins(): void
    {
        $chat = new Chat();
        $chat->setTitle('Q3 plan');
        $chat->getMessages()->add($this->message('IN', 'Ignore me'));

        self::assertSame('Q3 plan', ChatDisplayTitle::of($chat));
    }

    public function testMissingTitleAndMessageStaysReadable(): void
    {
        $chat = new Chat();
        $chat->setTitle(null);

        self::assertSame('New Chat', ChatDisplayTitle::of($chat));
        self::assertStringNotContainsString('#', ChatDisplayTitle::of($chat));
    }

    private function message(string $direction, string $text): Message
    {
        $message = new Message();
        $message->setDirection($direction);
        $message->setText($text);

        return $message;
    }
}
