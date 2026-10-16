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
use App\Service\Iam\IamConfig;
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
            $this->audience(true),
        ))->publish($chat, 'OUT');

        self::assertEqualsCanonicalizing(['user:3', 'user:42', 'user:8', 'user:15'], $published);
        self::assertIsArray($payload);
        self::assertNull($payload['preview']);
        self::assertSame(9, $payload['chat_id']);
    }

    public function testDisabledAudienceDoesNotFanOutToEveryAccount(): void
    {
        $publisher = $this->createMock(RealtimePublisherInterface::class);
        $publisher->expects(self::once())
            ->method('publishMany')
            ->willReturnCallback(static function (array $channels): void {
                $names = array_map(static fn (ChannelInterface $channel): string => $channel->name(), $channels);
                self::assertEqualsCanonicalizing(['user:3', 'user:42'], $names);
            });

        $userShare = (new Share())->setSubjectType(Share::SUBJECT_USER)->setSubjectId(42)->setGrantedBy(3);
        // Written by the owner, not the platform: grants nothing while the audience is off.
        $everyone = (new Share())->setSubjectType(Share::SUBJECT_EVERYONE)->setSubjectId(0)->setGrantedBy(3);
        $shares = $this->createStub(ShareRepository::class);
        $shares->method('findForResource')->willReturn([$userShare, $everyone]);

        $users = $this->createMock(UserRepository::class);
        $users->expects(self::never())->method('findIdsAfter');

        $chat = new Chat();
        $chat->setUserId(3);
        $chat->updateTimestamp();
        $id = new \ReflectionProperty(Chat::class, 'id');
        $id->setValue($chat, 9);

        (new ChatAudienceNotifier(
            new ChatActivityNotifier($publisher, new NullLogger()),
            $shares,
            $this->createStub(GroupMemberRepository::class),
            $users,
            $this->audience(false),
        ))->publish($chat, 'OUT');
    }

    /**
     * The notifier asks one question per share: does this everyone row still
     * reach accounts? A person's grant follows the policy; a platform grant
     * always reaches (mirrors {@see IamConfig::everyoneShareReaches()}).
     */
    private function audience(bool $enabled): IamConfig
    {
        $iam = $this->createStub(IamConfig::class);
        $iam->method('everyoneShareReaches')->willReturnCallback(
            static fn (Share $share): bool => Share::SUBJECT_EVERYONE !== $share->getSubjectType()
                || $share->isPlatformGrant()
                || $enabled
        );

        return $iam;
    }
}
