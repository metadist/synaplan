<?php

declare(strict_types=1);

namespace App\Service\Telegram;

final readonly class TelegramWebhookDecision
{
    private function __construct(
        public bool $dispatch,
        public ?int $botId,
    ) {
    }

    public static function drop(): self
    {
        return new self(false, null);
    }

    public static function dispatch(int $botId): self
    {
        return new self(true, $botId);
    }
}
