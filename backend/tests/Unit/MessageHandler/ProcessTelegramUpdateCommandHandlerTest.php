<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Entity\TelegramBot;
use App\Entity\User;
use App\Message\ProcessTelegramUpdateCommand;
use App\MessageHandler\ProcessTelegramUpdateCommandHandler;
use App\Repository\TelegramBotRepository;
use App\Repository\UserRepository;
use App\Service\Feature\AdminPreview;
use App\Service\Telegram\TelegramInboundService;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class ProcessTelegramUpdateCommandHandlerTest extends TestCase
{
    public function testAnAdminOwnersUpdateIsProcessed(): void
    {
        $bot = $this->bot(3);
        $inbound = $this->createMock(TelegramInboundService::class);
        $inbound->expects($this->once())->method('handle')->with(5, 7, ['update_id' => 7]);

        $this->handler($bot, $this->user('ADMIN'), $inbound)(new ProcessTelegramUpdateCommand(5, 7, ['update_id' => 7]));
    }

    public function testANonAdminOwnersUpdateIsDropped(): void
    {
        $inbound = $this->createMock(TelegramInboundService::class);
        $inbound->expects($this->never())->method('handle');

        $this->handler($this->bot(3), $this->user('NEW'), $inbound)(new ProcessTelegramUpdateCommand(5, 7, ['update_id' => 7]));
    }

    public function testAMissingBotIsDropped(): void
    {
        $bots = $this->createStub(TelegramBotRepository::class);
        $bots->method('find')->willReturn(null);
        $inbound = $this->createMock(TelegramInboundService::class);
        $inbound->expects($this->never())->method('handle');

        $handler = new ProcessTelegramUpdateCommandHandler(
            $inbound,
            $bots,
            new AdminPreview($this->createStub(UserRepository::class)),
            new NullLogger(),
        );
        $handler(new ProcessTelegramUpdateCommand(5, 7, ['update_id' => 7]));
    }

    private function handler(TelegramBot $bot, User $owner, TelegramInboundService $inbound): ProcessTelegramUpdateCommandHandler
    {
        $bots = $this->createStub(TelegramBotRepository::class);
        $bots->method('find')->willReturn($bot);
        $users = $this->createStub(UserRepository::class);
        $users->method('find')->willReturn($owner);

        return new ProcessTelegramUpdateCommandHandler($inbound, $bots, new AdminPreview($users), new NullLogger());
    }

    private function bot(int $ownerId): TelegramBot
    {
        $bot = new TelegramBot($ownerId, 'bot-key', 9, 'synaplan_bot');
        $id = new \ReflectionProperty(TelegramBot::class, 'id');
        $id->setValue($bot, 5);

        return $bot;
    }

    private function user(string $level): User
    {
        $user = new User();
        $user->setUserLevel($level);

        return $user;
    }
}
