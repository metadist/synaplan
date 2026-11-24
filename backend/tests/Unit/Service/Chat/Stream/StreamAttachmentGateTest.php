<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\GuestSession;
use App\Entity\User;
use App\Repository\MessageRepository;
use App\Service\Chat\Stream\StreamAttachmentGate;
use App\Service\Chat\Stream\StreamCaller;
use PHPUnit\Framework\TestCase;

final class StreamAttachmentGateTest extends TestCase
{
    private const PROCESSING_USER_ID = 7;
    private const SESSION_CHAT_ID = 12;
    private const FILE_ID = 363;

    public function testAGuestAttachesAFileFromTheChatOfTheirSession(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::once())
            ->method('isFileInChat')
            ->with(self::SESSION_CHAT_ID, self::FILE_ID)
            ->willReturn(true);

        self::assertTrue((new StreamAttachmentGate($messages))->allows($this->guest(self::SESSION_CHAT_ID), $this->file(self::PROCESSING_USER_ID)));
    }

    public function testAGuestDoesNotAttachAFileOfAnotherGuest(): void
    {
        // Both guests stream as the processing user, so the file's owner
        // matches. Only the chat tells them apart.
        $messages = $this->createStub(MessageRepository::class);
        $messages->method('isFileInChat')->willReturn(false);

        self::assertFalse((new StreamAttachmentGate($messages))->allows($this->guest(self::SESSION_CHAT_ID), $this->file(self::PROCESSING_USER_ID)));
    }

    public function testAGuestWithoutAChatAttachesNothing(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::never())->method('isFileInChat');

        self::assertFalse((new StreamAttachmentGate($messages))->allows($this->guest(null), $this->file(self::PROCESSING_USER_ID)));
    }

    public function testAPersonStillAttachesOnlyTheirOwnFiles(): void
    {
        $messages = $this->createMock(MessageRepository::class);
        $messages->expects(self::never())->method('isFileInChat');
        $gate = new StreamAttachmentGate($messages);
        $person = new StreamCaller($this->user(), 'en');

        self::assertTrue($gate->allows($person, $this->file(self::PROCESSING_USER_ID)));
        self::assertFalse($gate->allows($person, $this->file(8)));
    }

    private function guest(?int $chatId): StreamCaller
    {
        $session = new GuestSession();
        if (null !== $chatId) {
            $session->setChatId($chatId);
        }

        return new StreamCaller($this->user(), 'en', guestSession: $session);
    }

    private function user(): User
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::PROCESSING_USER_ID);

        return $user;
    }

    private function file(int $ownerId): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn(self::FILE_ID);
        $file->method('getUserId')->willReturn($ownerId);

        return $file;
    }
}
