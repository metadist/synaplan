<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime\Notifier;

use App\Entity\Chat;
use App\Entity\GroupMember;
use App\Entity\Share;
use App\Realtime\Channel\ChannelInterface;
use App\Realtime\Notifier\ChatActivityNotifier;
use App\Realtime\Notifier\ChatAudienceNotifier;
use App\Realtime\Publisher\RealtimePublisherInterface;
use App\Repository\GroupMemberRepository;
use App\Repository\ShareRepository;
use App\Repository\UserRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatAudienceNotifierTest extends TestCase
{
    public function testPublishesToOwnerShareSubjectsAndEveryoneWithoutMessageText(): void
    {
        $published = [];
        $payload = null;
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publishMany')
            ->willReturnCallback(
                static function (array $channels, string $event, array $body) use (&$published, &$payload): void {
                    self::assertSame('chat.activity', $event);
                    foreach ($channels as $channel) {
                        self::assertInstanceOf(ChannelInterface::class, $channel);
                        $published[] = $channel->name();
                    }
                    $payload = $body;
                }
            );

        $userShare = (new Share())->setSubjectType(Share::SUBJECT_USER)->setSubjectId(42);
        $groupShare = (new Share())->setSubjectType(Share::SUBJECT_GROUP)->setSubjectId(7);
        $everyone = (new Share())->setSubjectType(Share::SUBJECT_EVERYONE)->setSubjectId(0);

        $shares = $this->createStub(ShareRepository::class);
        $shares->method('findForResource')->willReturn([$userShare, $groupShare, $everyone]);

        $members = $this->createStub(GroupMemberRepository::class);
        $members->method('findByGroupId')->willReturn([new GroupMember(7, 42), new GroupMember(7, 8)]);

        $users = $this->createStub(UserRepository::class);
        $users->method('findIdsAfter')->willReturnOnConsecutiveCalls([15], []);

        $chat = new Chat();
        $chat->setUserId(3);
        $chat->updateTimestamp();
        $id = new \ReflectionProperty(Chat::class, 'id');
        $id->setValue($chat, 9);

        (new ChatAudienceNotifier(
            new ChatActivityNotifier($publisher, new NullLogger()),
            $shares,
            $members,
            $users,
        ))->publish($chat, 'OUT');

        self::assertEqualsCanonicalizing(['user:3', 'user:42', 'user:8', 'user:15'], $published);
        self::assertIsArray($payload);
        self::assertNull($payload['preview']);
        self::assertSame(9, $payload['chat_id']);
    }
}
