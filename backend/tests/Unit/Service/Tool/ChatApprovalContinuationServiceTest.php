<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Tool;

use App\Entity\Approval;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\User;
use App\Realtime\Channel\UserChannel;
use App\Realtime\Publisher\RealtimePublisherInterface;
use App\Repository\ChatRepository;
use App\Repository\MessageRepository;
use App\Repository\UserRepository;
use App\Service\Tool\ChatApprovalContinuationService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Translation\TranslatorInterface;

final class ChatApprovalContinuationServiceTest extends TestCase
{
    public function testSkipsNonChatApprovals(): void
    {
        [$service, $messages, $publisher] = $this->service();
        $messages->expects(self::never())->method('find');
        $messages->expects(self::never())->method('save');
        $publisher->expects(self::never())->method('publish');

        self::assertNull($service->continueChat(
            $this->approval('task_run:9:n3'),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'ok'
        ));
    }

    public function testSkipsGatewayApprovalsWithoutMessage(): void
    {
        [$service, $messages, $publisher] = $this->service();
        $messages->expects(self::never())->method('find');
        $messages->expects(self::never())->method('save');
        $publisher->expects(self::never())->method('publish');

        self::assertNull($service->continueChat(
            $this->approval('chat:0'),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'ok'
        ));
    }

    public function testSkipsWhenTriggerMessageIsMissing(): void
    {
        [$service, $messages, $publisher] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn(null);
        $messages->expects(self::never())->method('save');
        $publisher->expects(self::never())->method('publish');

        self::assertNull($service->continueChat(
            $this->approval('chat:11'),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'ok'
        ));
    }

    public function testSkipsWhenChatBelongsToAnotherUser(): void
    {
        [$service, $messages, $publisher, $chats] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $chats->expects(self::any())->method('find')->with(5)->willReturn($this->chat(5, 8));
        $messages->expects(self::never())->method('save');
        $publisher->expects(self::never())->method('publish');

        self::assertNull($service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'ok'
        ));
    }

    public function testContinuesWhenTriggerAuthorDiffersFromChatOwner(): void
    {
        // Message authors can differ from the chat owner (e.g. human operator
        // messages in widget chats); ownership is decided by the chat.
        [$service, $messages, , $chats, $users] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 8, chatId: 5));
        $this->wiresChatAndOwner($chats, $users);

        $messages->expects(self::once())->method('save');
        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            null
        );
        self::assertIsCallable($announce);
    }

    public function testSkipsWhenTriggerHasNoChat(): void
    {
        [$service, $messages, $publisher] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: null));
        $messages->expects(self::never())->method('save');
        $publisher->expects(self::never())->method('publish');

        self::assertNull($service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'ok'
        ));
    }

    public function testSkipsWhenChatIsGone(): void
    {
        [$service, $messages, $publisher, $chats] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $chats->expects(self::any())->method('find')->with(5)->willReturn(null);
        $messages->expects(self::never())->method('save');
        $publisher->expects(self::never())->method('publish');

        self::assertNull($service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'ok'
        ));
    }

    public function testAppendsExecutedFollowUpAndPublishes(): void
    {
        [$service, $messages, $publisher, $chats, $users, $translator] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $this->wiresChatAndOwner($chats, $users, 'de');

        $messages->expects(self::once())->method('save')->with(
            self::callback(static function (Message $message): bool {
                self::assertSame(7, $message->getUserId());
                self::assertSame(5, $message->getChatId());
                self::assertSame('OUT', $message->getDirection());
                self::assertSame('complete', $message->getStatus());
                self::assertSame('CHAT', $message->getMessageType());
                self::assertSame('tools', $message->getTopic());
                self::assertSame('en', $message->getLanguage());
                self::assertSame(0, $message->getFile());
                self::assertStringStartsWith(
                    'approval.chat_followup.executed_with_result|approvals|de|',
                    $message->getText()
                );
                self::assertStringContainsString('Call ACME', $message->getText());
                self::assertStringContainsString('order id 42', $message->getText());

                return true;
            }),
            false
        );
        $publisher->expects(self::once())->method('publish')->with(
            self::callback(static fn (UserChannel $channel): bool => 7 === $channel->userId),
            ChatApprovalContinuationService::EVENT_CHAT_CONTINUED,
            ['approvalId' => 42, 'chatId' => 5, 'outcome' => ChatApprovalContinuationService::OUTCOME_EXECUTED]
        );

        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7, id: 42, preview: 'Call ACME'),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            'order id 42'
        );
        self::assertIsCallable($announce);
        $announce();
    }

    public function testExecutedWithoutDetailUsesShortTemplate(): void
    {
        [$service, $messages, , $chats, $users] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $this->wiresChatAndOwner($chats, $users);

        $messages->expects(self::once())->method('save')->with(
            self::callback(static function (Message $message): bool {
                self::assertStringStartsWith('approval.chat_followup.executed|', $message->getText());
                self::assertStringNotContainsString('executed_with_result', $message->getText());

                return true;
            }),
            false
        );

        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            null
        );
        self::assertIsCallable($announce);
    }

    public function testFailedWithoutDetailUsesGenericTemplate(): void
    {
        [$service, $messages, , $chats, $users] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $this->wiresChatAndOwner($chats, $users);

        $messages->expects(self::once())->method('save')->with(
            self::callback(static function (Message $message): bool {
                self::assertStringStartsWith('approval.chat_followup.failed_generic|', $message->getText());

                return true;
            }),
            false
        );

        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_FAILED,
            '  '
        );
        self::assertIsCallable($announce);
    }

    public function testRejectedIgnoresDetail(): void
    {
        [$service, $messages, , $chats, $users] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $this->wiresChatAndOwner($chats, $users);

        $messages->expects(self::once())->method('save')->with(
            self::callback(static function (Message $message): bool {
                self::assertStringStartsWith('approval.chat_followup.rejected|', $message->getText());

                return true;
            }),
            false
        );

        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_REJECTED,
            'should be ignored'
        );
        self::assertIsCallable($announce);
    }

    public function testUnsupportedFallsBackToToolNameWhenPreviewIsEmpty(): void
    {
        [$service, $messages, , $chats, $users] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $this->wiresChatAndOwner($chats, $users);

        $messages->expects(self::once())->method('save')->with(
            self::callback(static function (Message $message): bool {
                self::assertStringStartsWith('approval.chat_followup.unsupported|', $message->getText());
                self::assertStringContainsString('code_run', $message->getText());

                return true;
            }),
            false
        );

        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7, tool: 'code_run', preview: null),
            ChatApprovalContinuationService::OUTCOME_UNSUPPORTED,
            null
        );
        self::assertIsCallable($announce);
    }

    public function testFallsBackToEnglishWithoutOwner(): void
    {
        [$service, $messages, , $chats, $users] = $this->service();
        $messages->expects(self::any())->method('find')->with(11)->willReturn($this->trigger(userId: 7, chatId: 5));
        $chats->method('find')->willReturn($this->chat());
        $users->expects(self::any())->method('find')->with(7)->willReturn(null);

        $messages->expects(self::once())->method('save')->with(
            self::callback(static function (Message $message): bool {
                self::assertStringContainsString('|en|', $message->getText());

                return true;
            }),
            false
        );

        $announce = $service->continueChat(
            $this->approval('chat:11', ownerId: 7),
            ChatApprovalContinuationService::OUTCOME_EXECUTED,
            null
        );
        self::assertIsCallable($announce);
    }

    private function approval(string $requestedBy, int $ownerId = 7, int $id = 42, string $tool = 'custom:acme', ?string $preview = 'Call ACME'): Approval
    {
        $approval = $this->createStub(Approval::class);
        $approval->method('getId')->willReturn($id);
        $approval->method('getOwnerId')->willReturn($ownerId);
        $approval->method('getRequestedBy')->willReturn($requestedBy);
        $approval->method('getTool')->willReturn($tool);
        $approval->method('getPreview')->willReturn($preview);

        return $approval;
    }

    private function trigger(int $userId, ?int $chatId): Message
    {
        $message = $this->createStub(Message::class);
        $message->method('getUserId')->willReturn($userId);
        $message->method('getChatId')->willReturn($chatId);
        $message->method('getMessageType')->willReturn('CHAT');
        $message->method('getTopic')->willReturn('tools');
        $message->method('getLanguage')->willReturn('en');

        return $message;
    }

    private function chat(int $id = 5, int $userId = 7): Chat
    {
        $chat = $this->createStub(Chat::class);
        $chat->method('getId')->willReturn($id);
        $chat->method('getUserId')->willReturn($userId);

        return $chat;
    }

    private function owner(string $locale = 'en'): User
    {
        $owner = $this->createStub(User::class);
        $owner->method('getLocale')->willReturn($locale);

        return $owner;
    }

    /**
     * @param ChatRepository&MockObject $chats
     * @param UserRepository&MockObject $users
     */
    private function wiresChatAndOwner(MockObject $chats, MockObject $users, string $locale = 'en'): void
    {
        $chats->method('find')->willReturn($this->chat());
        $users->method('find')->willReturn($this->owner($locale));
    }

    /**
     * @return array{0: ChatApprovalContinuationService, 1: MessageRepository&MockObject, 2: RealtimePublisherInterface&MockObject, 3: ChatRepository&MockObject, 4: UserRepository&MockObject, 5: TranslatorInterface&MockObject}
     */
    private function service(): array
    {
        $messages = $this->createMock(MessageRepository::class);
        $chats = $this->createMock(ChatRepository::class);
        $users = $this->createMock(UserRepository::class);
        $translator = $this->createMock(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static function (string $id, array $params = [], ?string $domain = null, ?string $locale = null): string {
                return $id.'|'.(string) $domain.'|'.(string) $locale.'|'.(string) json_encode($params);
            }
        );
        $publisher = $this->createMock(RealtimePublisherInterface::class);

        return [
            new ChatApprovalContinuationService($messages, $chats, $users, $translator, $publisher),
            $messages,
            $publisher,
            $chats,
            $users,
            $translator,
        ];
    }
}
