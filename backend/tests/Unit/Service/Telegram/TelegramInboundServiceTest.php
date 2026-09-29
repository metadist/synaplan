<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\AI\Exception\ChatFailureReason;
use App\Entity\Chat;
use App\Entity\Message;
use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\Digest\MessageReferenceResolver;
use App\Service\Message\ChatErrorPresenter;
use App\Service\Message\ChatErrorView;
use App\Service\Message\MessageProcessor;
use App\Service\RateLimitService;
use App\Service\Telegram\TelegramBotApi;
use App\Service\Telegram\TelegramConnectionService;
use App\Service\Telegram\TelegramInboundService;
use App\Service\UserMemoryService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[AllowMockObjectsWithoutExpectations]
final class TelegramInboundServiceTest extends TestCase
{
    public function testPhotoGetsOneSentenceAndIsNotStored(): void
    {
        $sent = [];
        $service = $this->service($this->connectedBot(), $sent, persist: false);

        $service->handle(5, $this->update(['text' => 'look', 'photo' => [['file_id' => 'x']]]));

        $this->assertSame(['Only text messages for now.'], $sent);
    }

    public function testStrangerIsRefusedAndNothingIsStored(): void
    {
        $sent = [];
        $bot = $this->connectedBot();
        $bot->setTgUserId('111');
        $service = $this->service($bot, $sent, persist: false);

        $service->handle(5, $this->update(['text' => 'hello'], fromId: 999));

        $this->assertSame(['This bot only answers its owner.'], $sent);
    }

    public function testPairingStoresTheTurnWithoutTheCode(): void
    {
        $sent = [];
        $messages = [];
        $bot = $this->connectedBot();
        $bot->setStatus(TelegramBot::STATUS_PENDING);
        $bot->setTgUserId(null);
        $service = $this->service($bot, $sent, messages: $messages, pair: true);

        $service->handle(5, $this->update(['text' => '/start GOODCODE', 'message_id' => 10]));

        $this->assertSame(TelegramBot::STATUS_CONNECTED, $bot->getStatus());
        $this->assertSame(['Connected. Send a message whenever you want a reply.'], $sent);
        $this->assertCount(2, $messages);
        $this->assertSame('Connected from Telegram.', $messages[0]->getText());
        $this->assertSame('IN', $messages[0]->getDirection());
        $this->assertSame('TGRM', $messages[0]->getMessageType());
        $this->assertSame('complete', $messages[1]->getStatus());
        $this->assertSame('OUT', $messages[1]->getDirection());
        $this->assertStringNotContainsString('GOODCODE', $messages[0]->getText());
    }

    public function testTextTurnStoresInboundAndOutbound(): void
    {
        $sent = [];
        $messages = [];
        $service = $this->service($this->connectedBot(), $sent, messages: $messages, reply: 'Four.');

        $service->handle(5, $this->update(['text' => 'What is 2+2?', 'message_id' => '15']));

        $this->assertSame(['Four.'], $sent);
        $this->assertCount(2, $messages);
        $this->assertSame('complete', $messages[0]->getStatus());
        $this->assertSame('What is 2+2?', $messages[0]->getText());
        $this->assertSame('Four.', $messages[1]->getText());
        $this->assertSame('telegram', $messages[0]->getMeta('channel'));
        $this->assertSame('15', $messages[0]->getMeta('external_id'));
    }

    public function testAiFailureSendsThePresenterSentence(): void
    {
        $sent = [];
        $service = $this->service(
            $this->connectedBot(),
            $sent,
            reply: null,
            failure: 'The model is not available. Pick another model in Settings.',
        );

        $service->handle(5, $this->update(['text' => 'hello']));

        $this->assertSame(['The model is not available. Pick another model in Settings.'], $sent);
    }

    public function testRateLimitDoesNotCallTheModel(): void
    {
        $sent = [];
        $processor = $this->createMock(MessageProcessor::class);
        $processor->expects($this->never())->method('process');
        $service = $this->service($this->connectedBot(), $sent, processor: $processor, allowed: false);

        $service->handle(5, $this->update(['text' => 'hello']));

        $this->assertSame(['You have reached the message limit. Try again later.'], $sent);
    }

    /**
     * @param list<string>  $sent
     * @param list<Message> $messages
     */
    private function service(
        TelegramBot $bot,
        array &$sent,
        bool $persist = true,
        array &$messages = [],
        bool $pair = false,
        ?string $reply = null,
        ?string $failure = null,
        ?MessageProcessor $processor = null,
        bool $allowed = true,
    ): TelegramInboundService {
        /** @var list<object> $pending */
        $pending = [];
        /** @var array<int, Chat> $chats */
        $chats = [];
        $seq = 1;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(function (string $class, mixed $id) use ($bot, &$chats): ?object {
            if (TelegramBot::class === $class) {
                return $bot;
            }
            if (Chat::class === $class && is_int($id)) {
                return $chats[$id] ?? null;
            }

            return null;
        });
        if (!$persist) {
            $em->expects($this->never())->method('persist');
        } else {
            $em->method('persist')->willReturnCallback(function (object $entity) use (&$pending): void {
                $pending[] = $entity;
            });
            $em->method('flush')->willReturnCallback(function () use (&$pending, &$seq, &$chats, &$messages): void {
                foreach ($pending as $entity) {
                    $id = new \ReflectionProperty($entity, 'id');
                    if (null !== $id->getValue($entity)) {
                        continue;
                    }
                    $id->setValue($entity, $seq);
                    if ($entity instanceof Chat) {
                        $chats[$seq] = $entity;
                    }
                    if ($entity instanceof Message) {
                        $messages[] = $entity;
                    }
                    ++$seq;
                }
                $pending = [];
            });
        }

        $user = $this->createStub(User::class);
        $user->method('getId')->willReturn(7);
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturn($user);

        $connections = $this->createMock(TelegramConnectionService::class);
        $connections->method('revealToken')->willReturn('123456789:AAHexampleToken');
        $connections->method('pair')->willReturnCallback(function (TelegramBot $row, string $code, string $tgUser, string $tgChat) use ($pair): bool {
            if (!$pair || 'GOODCODE' !== $code) {
                return false;
            }
            $row->setStatus(TelegramBot::STATUS_CONNECTED);
            $row->setTgUserId($tgUser);
            $row->setTgChatId($tgChat);

            return true;
        });
        $connections->method('attachChat')->willReturnCallback(function (TelegramBot $row, int $chatId): void {
            $row->setChatId($chatId);
        });

        $api = $this->createMock(TelegramBotApi::class);
        $api->method('sendMessage')->willReturnCallback(function (string $token, string $chatId, string $text) use (&$sent): void {
            $sent[] = $text;
        });

        if (null === $processor) {
            $processor = $this->createMock(MessageProcessor::class);
            if (null !== $failure) {
                $processor->method('process')->willReturn(['success' => false]);
            } elseif (null !== $reply) {
                $processor->method('process')->willReturn([
                    'success' => true,
                    'response' => [
                        'content' => $reply,
                        'metadata' => ['provider' => 'test', 'model' => 'test', 'usage' => [], 'model_id' => 1],
                    ],
                ]);
            }
        }

        $errors = $this->createMock(ChatErrorPresenter::class);
        $errors->method('presentFromResult')->willReturn(new ChatErrorView(
            ChatFailureReason::ModelUnavailable,
            $failure ?? 'unavailable',
            null,
            true,
            'raw',
        ));

        $limits = $this->createMock(RateLimitService::class);
        $limits->method('checkLimit')->willReturn(['allowed' => $allowed]);

        $memories = $this->createMock(UserMemoryService::class);
        $memories->method('resolveMemoryTags')->willReturnArgument(0);
        $references = $this->createMock(MessageReferenceResolver::class);
        $references->method('resolveMessageTags')->willReturnArgument(0);

        $service = new TelegramInboundService(
            $em,
            $users,
            $connections,
            $api,
            $processor,
            $errors,
            $limits,
            $memories,
            $references,
            new NullLogger(),
        );

        return $service;
    }

    private function connectedBot(): TelegramBot
    {
        $bot = new TelegramBot(7, 'bot-key', 4242, 'synaplan_test_bot');
        $id = new \ReflectionProperty(TelegramBot::class, 'id');
        $id->setValue($bot, 5);
        $bot->setStatus(TelegramBot::STATUS_CONNECTED);
        $bot->setTgUserId('555');
        $bot->setTgChatId('555');

        return $bot;
    }

    /**
     * @param array<string, mixed> $message
     *
     * @return array<string, mixed>
     */
    private function update(array $message, int $fromId = 555): array
    {
        return [
            'update_id' => 1,
            'message' => $message + [
                'message_id' => 1,
                'from' => ['id' => $fromId],
                'chat' => ['id' => $fromId, 'type' => 'private'],
            ],
        ];
    }
}
