<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime\Notifier;

use App\Entity\Chat;
use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Realtime\Publisher\RealtimePublisherInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Inbound-channel activity (WhatsApp/email) publishes a `chat.activity` event
 * to the owner's per-user Centrifugo channel so an open browser re-sorts the
 * chat to the top without a manual reload (#1372).
 */
final class ChatActivityNotifierTest extends TestCase
{
    public function testPublishesActivityToOwnerUserChannel(): void
    {
        $chat = $this->chatWithId(201);

        $captured = null;
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publish')
            ->willReturnCallback(function (ChannelInterface $channel, string $eventType, array $payload) use (&$captured): void {
                $captured = ['channel' => $channel->name(), 'event' => $eventType, 'payload' => $payload];
            });

        (new ChatActivityNotifier($publisher, new NullLogger()))
            ->publishActivity($chat, 7, 'IN', 'Hello from WhatsApp');

        self::assertNotNull($captured);
        self::assertSame('user:7', $captured['channel']);
        self::assertSame('chat.activity', $captured['event']);
        self::assertSame(201, $captured['payload']['chat_id']);
        self::assertSame('IN', $captured['payload']['direction']);
        self::assertSame('Hello from WhatsApp', $captured['payload']['preview']);
    }

    public function testDoesNotPublishForAnonymousUser(): void
    {
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::never())->method('publish');

        (new ChatActivityNotifier($publisher, new NullLogger()))
            ->publishActivity($this->chatWithId(1), 0, 'IN', 'x');
    }

    public function testNeverThrowsWhenPublisherFails(): void
    {
        $publisher = $this->createStub(RealtimePublisherInterface::class);
        $publisher->method('publish')->willThrowException(new \RuntimeException('centrifugo down'));

        $this->expectNotToPerformAssertions();
        (new ChatActivityNotifier($publisher, new NullLogger()))
            ->publishActivity($this->chatWithId(5), 5, 'IN', 'x');
    }

    private function chatWithId(int $id): Chat
    {
        $chat = new Chat();
        $chat->updateTimestamp();

        $reflection = new \ReflectionProperty(Chat::class, 'id');
        $reflection->setValue($chat, $id);

        return $chat;
    }
}
