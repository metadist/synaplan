<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Telegram;

use App\Entity\TelegramBot;
use App\Repository\TelegramBotRepository;
use App\Service\Telegram\TelegramWebhookAcceptor;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

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

    private function acceptor(TelegramBot $bot): TelegramWebhookAcceptor
    {
        $bots = $this->createMock(TelegramBotRepository::class);
        $bots->method('findOneByBotKey')->willReturn($bot);

        return new TelegramWebhookAcceptor($bots, new ArrayAdapter(), new NullLogger());
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
