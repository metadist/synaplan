<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use App\Service\Desktop\DesktopJobChatTitle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class DesktopJobChatTitleTest extends TestCase
{
    public function testBuildUsesTheSkillAndAShortInstruction(): void
    {
        self::assertSame('hello-files: Say hello', DesktopJobChatTitle::build('hello-files', "Say\nhello"));
    }

    public function testBuildTruncatesALongInstruction(): void
    {
        $title = DesktopJobChatTitle::build('hello-files', str_repeat('a', 80));

        self::assertSame(60, mb_strlen($title));
        self::assertStringEndsWith('…', $title);
    }

    public function testNameIfUntitledReplacesAPlaceholderAndLeavesARealTitle(): void
    {
        $chat = (new Chat())->setUserId(5)->setTitle('New Chat');
        $chats = $this->createMock(ChatRepository::class);
        $chats->expects(self::atLeastOnce())->method('find')->with(9)->willReturn($chat);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $namer = new DesktopJobChatTitle($chats, $em);

        self::assertSame('hello-files: Say hello', $namer->nameIfUntitled(5, 9, 'hello-files', 'Say hello'));
        self::assertSame('hello-files: Say hello', $chat->getTitle());

        self::assertNull($namer->nameIfUntitled(5, 9, 'other', 'ignored'));
        self::assertSame('hello-files: Say hello', $chat->getTitle());
    }

    public function testNameIfUntitledIgnoresAnotherPersonsChat(): void
    {
        $chat = (new Chat())->setUserId(8)->setTitle('New Chat');
        $chats = $this->createMock(ChatRepository::class);
        $chats->method('find')->willReturn($chat);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');
        $namer = new DesktopJobChatTitle($chats, $em);

        self::assertNull($namer->nameIfUntitled(5, 9, 'hello-files', 'Say hello'));
        self::assertSame('New Chat', $chat->getTitle());
    }
}
