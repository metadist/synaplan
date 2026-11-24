<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Stream;

use App\Entity\File;
use App\Entity\GuestSession;
use App\Entity\User;
use App\Entity\WidgetSession;
use App\Service\Chat\Stream\StreamCaller;
use PHPUnit\Framework\TestCase;

/**
 * A widget visitor and a guest stream as a user they are not. What that
 * user owns is not theirs: they reach the chat and the uploads of their own
 * session and nothing else.
 */
final class StreamCallerSessionScopeTest extends TestCase
{
    private const OWNER_ID = 7;

    public function testAPersonWritesIntoAnyChatTheOwnershipCheckLetsThrough(): void
    {
        self::assertTrue((new StreamCaller($this->owner(), 'en'))->mayWriteInto(12));
    }

    public function testAWidgetVisitorWritesOnlyIntoTheChatOfTheirSession(): void
    {
        $visitor = new StreamCaller($this->owner(), 'en', widgetSession: $this->widgetSession(id: 3, chatId: 12));

        self::assertTrue($visitor->mayWriteInto(12));
        self::assertFalse($visitor->mayWriteInto(13));
        self::assertFalse((new StreamCaller($this->owner(), 'en', widgetSession: $this->widgetSession(id: 3, chatId: null)))->mayWriteInto(13));
    }

    public function testAGuestWritesOnlyIntoTheChatOfTheirSession(): void
    {
        $guest = new StreamCaller($this->owner(), 'en', guestSession: (new GuestSession())->setChatId(12));

        self::assertTrue($guest->mayWriteInto(12));
        self::assertFalse($guest->mayWriteInto(13));
        self::assertFalse((new StreamCaller($this->owner(), 'en', guestSession: new GuestSession()))->mayWriteInto(13));
    }

    public function testAPersonAttachesTheirOwnFiles(): void
    {
        $person = new StreamCaller($this->owner(), 'en');

        self::assertTrue($person->mayAttach($this->file(userId: self::OWNER_ID, sessionId: null)));
        self::assertFalse($person->mayAttach($this->file(userId: 8, sessionId: null)));
    }

    public function testAWidgetVisitorAttachesOnlyTheUploadsOfTheirSession(): void
    {
        $visitor = new StreamCaller($this->owner(), 'en', widgetSession: $this->widgetSession(id: 3, chatId: 12));

        self::assertTrue($visitor->mayAttach($this->file(userId: 0, sessionId: 3)));
        self::assertFalse($visitor->mayAttach($this->file(userId: self::OWNER_ID, sessionId: null)), 'a file of the widget owner');
        self::assertFalse($visitor->mayAttach($this->file(userId: 0, sessionId: 4)), 'an upload of another visitor');
        self::assertFalse(
            (new StreamCaller($this->owner(), 'en', widgetSession: $this->widgetSession(id: null, chatId: null)))->mayAttach($this->file(userId: self::OWNER_ID, sessionId: null)),
            'a session without an id matches no file',
        );
    }

    public function testAGuestProvesNothingByOwningTheSharedProcessingUser(): void
    {
        $guest = new StreamCaller($this->owner(), 'en', guestSession: (new GuestSession())->setChatId(12));

        self::assertFalse($guest->mayAttach($this->file(userId: self::OWNER_ID, sessionId: null)));
    }

    private function owner(): User
    {
        $owner = $this->createStub(User::class);
        $owner->method('getId')->willReturn(self::OWNER_ID);

        return $owner;
    }

    private function widgetSession(?int $id, ?int $chatId): WidgetSession
    {
        $session = $this->createStub(WidgetSession::class);
        $session->method('getId')->willReturn($id);
        $session->method('getChatId')->willReturn($chatId);

        return $session;
    }

    private function file(int $userId, ?int $sessionId): File
    {
        $file = $this->createStub(File::class);
        $file->method('getUserId')->willReturn($userId);
        $file->method('getUserSessionId')->willReturn($sessionId);

        return $file;
    }
}
