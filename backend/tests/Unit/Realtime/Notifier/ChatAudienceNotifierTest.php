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
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ChatAudienceNotifierTest extends TestCase
{
    public function testPublishesToOwnerAndCurrentShareSubjects(): void
    {
        $published = [];
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::exactly(3))
            ->method('publish')
            ->willReturnCallback(
                static function (ChannelInterface $channel) use (&$published): void {
                    $published[] = $channel->name();
                }
            );

        $userShare = (new Share())->setSubjectType(Share::SUBJECT_USER)->setSubjectId(42);
        $groupShare = (new Share())->setSubjectType(Share::SUBJECT_GROUP)->setSubjectId(7);
        $everyone = (new Share())->setSubjectType(Share::SUBJECT_EVERYONE)->setSubjectId(0);

        $shares = $this->createStub(ShareRepository::class);
        $shares->method('findForResource')->willReturn([$userShare, $groupShare, $everyone]);

        $members = $this->createStub(GroupMemberRepository::class);
        $members->method('findByGroupId')->willReturn([new GroupMember(7, 42), new GroupMember(7, 8)]);

        $chat = new Chat();
        $chat->setUserId(3);
        $chat->updateTimestamp();
        $id = new \ReflectionProperty(Chat::class, 'id');
        $id->setValue($chat, 9);

        (new ChatAudienceNotifier(new ChatActivityNotifier($publisher, new NullLogger()), $shares, $members))
            ->publish($chat, 'OUT', 'Done');

        self::assertEqualsCanonicalizing(['user:3', 'user:42', 'user:8'], $published);
    }
}
