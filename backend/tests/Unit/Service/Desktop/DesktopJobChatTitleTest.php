<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Desktop;

use App\Entity\Chat;
use App\Repository\ChatRepository;
use App\Service\Desktop\DesktopJobChatTitle;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

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
        $namer = new DesktopJobChatTitle($chats, $em, $this->createMock(LoggerInterface::class));

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
        $namer = new DesktopJobChatTitle($chats, $em, $this->createMock(LoggerInterface::class));

        self::assertNull($namer->nameIfUntitled(5, 9, 'hello-files', 'Say hello'));
        self::assertSame('New Chat', $chat->getTitle());
    }

    public function testNameIfUntitledReplacesANumberedChatPlaceholder(): void
    {
        $chat = (new Chat())->setUserId(5)->setTitle('Chat 12');
        $chats = $this->createMock(ChatRepository::class);
        $chats->method('find')->willReturn($chat);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('flush');
        $namer = new DesktopJobChatTitle($chats, $em, $this->createMock(LoggerInterface::class));

        self::assertSame('hello-files: Say hello', $namer->nameIfUntitled(5, 9, 'hello-files', 'Say hello'));
        self::assertSame('hello-files: Say hello', $chat->getTitle());
    }

    public function testNameIfUntitledKeepsTheJobWhenTheTitleCannotBeSaved(): void
    {
        $chat = (new Chat())->setUserId(5)->setTitle('New Chat');
        $chats = $this->createMock(ChatRepository::class);
        $chats->method('find')->willReturn($chat);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willThrowException(new \RuntimeException('database unavailable'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');
        $namer = new DesktopJobChatTitle($chats, $em, $logger);

        self::assertNull($namer->nameIfUntitled(5, 9, 'hello-files', 'Say hello'));
        self::assertSame('New Chat', $chat->getTitle());
    }
}
