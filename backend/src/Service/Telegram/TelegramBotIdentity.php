<?php

declare(strict_types=1);

namespace App\Service\Telegram;

final readonly class TelegramBotIdentity
{
    public function __construct(
        public int $id,
        public string $username,
    ) {
    }
}
