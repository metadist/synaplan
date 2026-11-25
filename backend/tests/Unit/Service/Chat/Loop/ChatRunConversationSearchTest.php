<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Chat\Loop;

use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Repository\ChatMessageSearchRepository;
use App\Service\Chat\Loop\ChatRunConversationSearch;
use App\Service\Chat\Loop\ChatRunScope;
use PHPUnit\Framework\TestCase;

/**
 * Earlier messages stay findable word for word (§0 #27): a fact from early
 * in the chat comes back with its message id, and only from this chat.
 */
final class ChatRunConversationSearchTest extends TestCase
{
    private const USER = 7;
    private const CHAT = 11;

    public function testAFactFromEarlyInTheChatIsFoundWithItsMessageId(): void
    {
        $messages = $this->createMock(ChatMessageSearchRepository::class);
        $messages->expects(self::once())->method('search')->with(self::USER, self::CHAT, ['Budget', 'küche'], 8, [40, 41])->willReturn([
            $this->message(10, 'IN', str_repeat('Vorher. ', 40).'Unser Budget für die Küche liegt bei 4.800 Euro. '.str_repeat('Danach. ', 60)),
            $this->message(12, 'OUT', 'Budget Küche: notiert.'),
        ]);

        $result = $this->search($messages)->run($this->scope(), ['query' => ' "Budget" küche Küche, a '], [40, 41]);

        self::assertStringContainsString('message 10 · the person · ', $result->text);
        self::assertStringContainsString('Unser Budget für die Küche liegt bei 4.800 Euro.', $result->text);
        self::assertStringContainsString('message 12 · assistant', $result->text);
        self::assertMatchesRegularExpression('/\n….+Budget.+…\n/u', $result->text, 'a long message comes back as a snippet around the word');
        self::assertLessThan(1_000, mb_strlen($result->text));
    }

    public function testAroundReadsTheNeighbours(): void
    {
        $messages = $this->createMock(ChatMessageSearchRepository::class);
        $messages->expects(self::once())->method('around')->with(self::USER, self::CHAT, 10, 3)->willReturn([
            $this->message(9, 'OUT', 'What is your budget?'),
            $this->message(10, 'IN', str_repeat('x', 2_000)),
        ]);
        $messages->expects(self::never())->method('search');

        $result = $this->search($messages)->run($this->scope(), ['around' => '10', 'query' => 'ignored']);

        self::assertStringStartsWith('message 9 · assistant · ', $result->text);
        self::assertStringContainsString(str_repeat('x', 1_500).'…', $result->text);
        self::assertStringNotContainsString(str_repeat('x', 1_501), $result->text);
    }

    public function testAMessageOfAnotherChatIsNotRead(): void
    {
        $messages = $this->createStub(ChatMessageSearchRepository::class);
        $messages->method('around')->willReturn([]);

        $result = $this->search($messages)->run($this->scope(), ['around' => 4]);

        self::assertTrue($result->failed);
        self::assertSame(ChatRunConversationSearch::NOT_IN_CHAT, $result->text);
    }

    public function testWithoutWordsNothingIsSearched(): void
    {
        $messages = $this->createMock(ChatMessageSearchRepository::class);
        $messages->expects(self::never())->method('search');

        $empty = $this->search($messages)->run($this->scope(), ['query' => ' a , ']);
        $none = $this->search($messages)->run($this->scope(), []);

        self::assertSame(ChatRunConversationSearch::NEEDS_INPUT, $empty->text);
        self::assertSame(ChatRunConversationSearch::NEEDS_INPUT, $none->text);
    }

    public function testNoHitSaysSo(): void
    {
        $result = $this->search($this->createStub(ChatMessageSearchRepository::class))->run($this->scope(), ['query' => 'Sauna']);

        self::assertFalse($result->failed);
        self::assertSame(ChatRunConversationSearch::NOTHING, $result->text);
    }

    public function testAtMostSixWordsAreUsed(): void
    {
        $messages = $this->createMock(ChatMessageSearchRepository::class);
        $messages->expects(self::once())->method('search')->with(self::USER, self::CHAT, ['one', 'two', 'three', 'four', 'five', 'six'], 8, [])->willReturn([]);

        $this->search($messages)->run($this->scope(), ['query' => 'one two three four five six seven']);
    }

    private function search(ChatMessageSearchRepository $messages): ChatRunConversationSearch
    {
        return new ChatRunConversationSearch($messages);
    }

    private function message(int $id, string $direction, string $text): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getId')->willReturn($id);
        $message->method('getDirection')->willReturn($direction);
        $message->method('getText')->willReturn($text);
        $message->method('getUnixTimestamp')->willReturn(1_795_000_000);

        return $message;
    }

    private function scope(): ChatRunScope
    {
        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(self::USER);
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn(self::CHAT);

        return new ChatRunScope($user, $chat, null);
    }
}
