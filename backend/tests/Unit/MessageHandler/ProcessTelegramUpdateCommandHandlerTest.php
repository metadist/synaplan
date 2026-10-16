<?php

declare(strict_types=1);

namespace App\Tests\Unit\MessageHandler;

use App\Message\ProcessTelegramUpdateCommand;
use App\MessageHandler\ProcessTelegramUpdateCommandHandler;
use App\Service\Telegram\TelegramInboundService;
use PHPUnit\Framework\TestCase;

final class ProcessTelegramUpdateCommandHandlerTest extends TestCase
{
    public function testEveryOwnersUpdateIsProcessed(): void
    {
        $inbound = $this->createMock(TelegramInboundService::class);
        $inbound->expects($this->once())->method('handle')->with(5, 7, ['update_id' => 7]);

        (new ProcessTelegramUpdateCommandHandler($inbound))(new ProcessTelegramUpdateCommand(5, 7, ['update_id' => 7]));
    }
}
