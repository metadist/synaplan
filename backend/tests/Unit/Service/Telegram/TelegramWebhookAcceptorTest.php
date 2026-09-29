<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Entity\TelegramBot;
use App\Entity\User;
use App\Repository\TelegramBotRepository;
use App\Repository\UserRepository;
use App\Service\Feature\AdminPreview;
use App\Service\Telegram\TelegramWebhookAcceptor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;

#[AllowMockObjectsWithoutExpectations]
final class TelegramWebhookAcceptorTest extends TestCase
{
    public function testMatchingSecretDispatchesOnce(): void
    {
        $secret = 'webhook-secret';
        $acceptor = $this->acceptor($this->bot($secret));

        $first = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);
        $repeat = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);

        $this->assertTrue($first->dispatch);
        $this->assertSame(5, $first->botId);
        $this->assertFalse($repeat->dispatch);
    }

    public function testBadSecretDoesNotConsumeTheUpdate(): void
    {
        $secret = 'webhook-secret';
        $acceptor = $this->acceptor($this->bot($secret));

        $rejected = $acceptor->decide('bot-key', 'wrong', ['update_id' => 7]);
        $accepted = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);

        $this->assertFalse($rejected->dispatch);
        $this->assertTrue($accepted->dispatch);
    }

    public function testEmptySecretHashIsRejected(): void
    {
        $bot = $this->bot('unused');
        $bot->setSecretHash('');
        $acceptor = $this->acceptor($bot);

        $decision = $acceptor->decide('bot-key', 'webhook-secret', ['update_id' => 7]);

        $this->assertFalse($decision->dispatch);
    }

    public function testReleaseLetsTelegramsRetryThrough(): void
    {
        $secret = 'webhook-secret';
        $acceptor = $this->acceptor($this->bot($secret));

        $first = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);
        $acceptor->release($first);
        $retry = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);

        $this->assertTrue($first->dispatch);
        $this->assertSame(7, $first->updateId);
        $this->assertTrue($retry->dispatch);
    }

    public function testANonAdminOwnerIsDroppedWithoutReservingTheUpdate(): void
    {
        $secret = 'webhook-secret';
        $member = new User();
        $member->setUserLevel('NEW');
        $admin = new User();
        $admin->setUserLevel('ADMIN');
        $users = $this->createMock(UserRepository::class);
        $users->method('find')->willReturnOnConsecutiveCalls($member, $admin);
        $acceptor = $this->acceptor($this->bot($secret), preview: new AdminPreview($users));

        $dropped = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);
        $accepted = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);

        $this->assertFalse($dropped->dispatch);
        $this->assertTrue($accepted->dispatch);
    }

    public function testAParallelDeliveryHoldingTheLockIsDropped(): void
    {
        $secret = 'webhook-secret';
        $locks = new LockFactory(new InMemoryStore());
        $acceptor = $this->acceptor($this->bot($secret), $locks);
        $held = $locks->createLock('telegram_update_bot-key_7_lock');
        $held->acquire();

        $decision = $acceptor->decide('bot-key', $secret, ['update_id' => 7]);

        $this->assertFalse($decision->dispatch);
    }

    private function acceptor(TelegramBot $bot, ?LockFactory $locks = null, ?AdminPreview $preview = null): TelegramWebhookAcceptor
    {
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByBotKey')->willReturn($bot);

        return new TelegramWebhookAcceptor(
            $bots,
            new ArrayAdapter(),
            $locks ?? new LockFactory(new InMemoryStore()),
            new NullLogger(),
            $preview ?? $this->adminPreview(),
        );
    }

    private function adminPreview(): AdminPreview
    {
        $admin = new User();
        $admin->setUserLevel('ADMIN');
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($admin);

        return new AdminPreview($users);
    }

    private function bot(string $secret): TelegramBot
    {
        $bot = new TelegramBot(1, 'bot-key', 9, 'synaplan_bot');
        $id = new \ReflectionProperty(TelegramBot::class, 'id');
        $id->setValue($bot, 5);
        $bot->setSecretHash(hash('sha256', $secret));

        return $bot;
    }
}
